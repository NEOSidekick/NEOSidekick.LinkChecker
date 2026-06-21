<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Domain\Crawler;

use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\RouterInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Service\LinkingService;

class ContentNodeCrawler
{
    /**
     * Pattern to match telephone numbers.
     *
     * @var string
     */
    protected const PATTERN_SUPPORTED_PHONE_NUMBERS = '/href="(tel):(\+?\d*)/';

    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var LinkingService
     * @Flow\Inject
     */
    protected $linkingService;

    /**
     * @var RouterInterface
     * @Flow\Inject
     */
    protected $router;

    /**
     * @var NodeDataRepository
     * @Flow\Inject
     */
    protected $nodeDataRepository;

    public function crawl(ContentContext $subgraph, Domain $domain, ?\DateTimeInterface $changedSince = null): array
    {
        $messages = [];

        $currentSiteNode = $subgraph->getCurrentSiteNode();
        $this->crawlNode($currentSiteNode, $subgraph, $domain, $messages, $changedSince);
        $this->crawlChildNodesRecursively($currentSiteNode, $subgraph, $domain, $messages, $changedSince);

        return $messages;
    }

    protected function crawlChildNodesRecursively(NodeInterface|TraversableNodeInterface $rootNode, ContentContext $subgraph, Domain $domain, array &$messages, ?\DateTimeInterface $changedSince = null): void
    {
        $childNodes = $rootNode->findChildNodes();

        foreach ($childNodes as $node) {
            $this->crawlNode($node, $subgraph, $domain, $messages, $changedSince);
            $this->crawlChildNodesRecursively($node, $subgraph, $domain, $messages, $changedSince);
        }

        // Free memory
        unset($childNodes);
    }

    /**
     * @see \Neos\Neos\Fusion\ConvertUrisImplementation::evaluate
     */
    protected function crawlPropertyForNodesAndAssets(
        mixed $property,
        ContentContext $subgraph,
        array &$unresolvedUris
    ): void {
        if (!is_string($property)) {
            return;
        }

        if (!str_contains($property, 'node://') && !str_contains($property, 'asset://')) {
            return;
        }

        preg_replace_callback(
            LinkingService::PATTERN_SUPPORTED_URIS,
            function (array $matches) use (
                $subgraph,
                &$unresolvedUris
            ) {
                $type = $matches[1];
                $identifier = $matches[0];
                $targetIsVisible = false;
                switch ($type) {
                    case 'node':
                        $linkedNodeId = NodeAggregateIdentifier::fromString(
                            str_replace('node://', '', $identifier)
                        );
                        $linkedNode = $subgraph->getNodeByIdentifier((string)$linkedNodeId);
                        $targetIsVisible = $linkedNode && $this->findIsNodeVisible($linkedNode);
                        break;
                    case 'asset':
                        $targetIsVisible = $this->linkingService->resolveAssetUri($identifier) !== null;
                        break;
                }

                if ($targetIsVisible === false) {
                    $unresolvedUris[] = $identifier;
                }

                return "";
            },
            $property
        );
    }

    private function crawlPropertyForTelephoneNumbers(
        $property,
        array &$invalidPhoneNumbers
    ): void {
        if (!is_string($property)) {
            return;
        }

        if (!str_contains($property, 'tel:')) {
            return;
        }

        preg_replace_callback(
            self::PATTERN_SUPPORTED_PHONE_NUMBERS,
            static function (array $matches) use (&$invalidPhoneNumbers) {
                if ($matches[1] === 'tel') {
                    $resolvedUri = str_starts_with($matches[2], '+') ? $matches[2] : null;
                } else {
                    $resolvedUri = null;
                }

                if ($resolvedUri === null) {
                    $invalidPhoneNumbers[] = 'tel:' . $matches[2];
                    return $matches[0];
                }

                return $resolvedUri;
            },
            $property
        );
    }

    protected function createResultItem(
        ContentContext $subgraph,
        Domain $domain,
        NodeInterface|TraversableNodeInterface $node,
        string $uri,
        int $statusCode,
        string $state = ResultItem::STATE_BROKEN
    ): void {
        $documentNode = $this->findClosestDocumentNode($node);

        $resultItem = new ResultItem();
        $resultItem->setDomain($domain->getHostname());
        $resultItem->setSource((string)$documentNode->getNodeAggregateIdentifier());
        $resultItem->setSourcePath((string)$documentNode->findNodePath());
        $resultItem->setTarget($uri);
        $resultItem->setState($state);

        if (str_starts_with($uri, 'node://')) {
            $subgraphWithHiddenNodes = $this->subgraphWithConfiguration($subgraph, [
                'invisibleContentShown' => true,
                'inaccessibleContentShown' => true
            ]);

            $targetNodeId = NodeAggregateIdentifier::fromString(
                str_replace('node://', '', $uri)
            );
            $targetNode = $subgraphWithHiddenNodes->getNodeByIdentifier((string)$targetNodeId);
            if ($targetNode) {
                $resultItem->setTargetPath((string)$targetNode->findNodePath());
            }
        }

        $resultItem->setStatusCode($statusCode);
        $resultItem->setCreatedAt($subgraph->getCurrentDateTime());
        $resultItem->setCheckedAt($subgraph->getCurrentDateTime());

        $this->resultItemRepository->add($resultItem);
    }

    private function findClosestDocumentNode(NodeInterface|TraversableNodeInterface $node): NodeInterface|TraversableNodeInterface
    {
        while ($node->getNodeType()->isOfType('Neos.Neos:Document') === false) {
            $node = $node->findParentNode();
        }
        return $node;
    }

    private function findIsNodeVisible(NodeInterface|TraversableNodeInterface $node): bool
    {
        do {
            $previousNode = $node;
            try {
                $node = $node->findParentNode();
            } catch (NodeException) {
                if ($previousNode->isRoot()) {
                    return true;
                }
                return false;
            }
        } while (true);
    }

    private function subgraphWithConfiguration(ContentContext $currentSubgraph, array $additionalConfiguration): ContentContext
    {
        $currentConfiguration = $currentSubgraph->getProperties();
        /** @var ContentContext $newSubgraph */
        $newSubgraph = $this->contextFactory->create(array_merge($currentConfiguration, $additionalConfiguration));
        return $newSubgraph;
    }

    protected function crawlNode(NodeInterface|TraversableNodeInterface $node, ContentContext $subgraph, Domain $domain, array &$messages, ?\DateTimeInterface $changedSince = null): void
    {
        // In incremental mode, skip nodes that have not been modified since the last crawl.
        if ($changedSince !== null) {
            $lastModified = $node->getNodeData()->getLastModificationDateTime();
            if ($lastModified instanceof \DateTimeInterface && $lastModified <= $changedSince) {
                return;
            }
        }

        $unresolvedUris = [];
        $invalidPhoneNumbers = [];

        // todo why use nodeData here and not the node?
        $properties = $node->getNodeData()->getProperties();

        foreach ($properties as $property) {
            $this->crawlPropertyForNodesAndAssets($property, $subgraph, $unresolvedUris);
            $this->crawlPropertyForTelephoneNumbers($property, $invalidPhoneNumbers);
        }

        foreach ($unresolvedUris as $uri) {
            $messages[] = 'Not found: ' . $uri;

            $this->createResultItem($subgraph, $domain, $node, $uri, 404);
        }
        foreach ($invalidPhoneNumbers as $phoneNumber) {
            $messages[] = 'Invalid format: ' . $phoneNumber;

            /* @see https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml - 490 is unassigned, and so we can use it */
            // A malformed phone number is a content hint, not a dead link, so it is a warning.
            $this->createResultItem($subgraph, $domain, $node, $phoneNumber, 490, ResultItem::STATE_WARNING);
        }
    }
}
