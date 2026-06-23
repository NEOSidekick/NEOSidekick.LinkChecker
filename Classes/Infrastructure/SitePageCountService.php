<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;

/**
 * @Flow\Scope("singleton")
 */
class SitePageCountService
{
    /**
     * @Flow\Inject
     * @var DomainService
     */
    protected $domainService;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    private ?int $totalVisibleDocumentPageCount = null;

    public function countTotalVisibleDocumentPages(): int
    {
        if ($this->totalVisibleDocumentPageCount !== null) {
            return $this->totalVisibleDocumentPageCount;
        }

        $pageKeys = [];
        foreach ($this->domainService->findAllSitesPrimaryDomain() as $domain) {
            /** @var ContentContext $context */
            $context = $this->contextFactory->create([
                'workspaceName' => 'live',
                'currentSite' => $domain->getSite(),
                'currentDomain' => $domain,
                'invisibleContentShown' => false,
                'inaccessibleContentShown' => false,
                'removedContentShown' => false,
            ]);

            $siteNode = $context->getCurrentSiteNode();
            if ($siteNode === null) {
                continue;
            }

            $this->collectDocumentPageKeys($siteNode, $pageKeys);
            $context->getFirstLevelNodeCache()->flush();
        }

        $this->totalVisibleDocumentPageCount = \count($pageKeys);
        return $this->totalVisibleDocumentPageCount;
    }

    public function countTotalLiveDocumentPages(): int
    {
        return $this->countTotalVisibleDocumentPages();
    }

    /**
     * @param array<string, true> $pageKeys
     */
    private function collectDocumentPageKeys(NodeInterface|TraversableNodeInterface $node, array &$pageKeys): void
    {
        if ($node->getNodeType()->isOfType('Neos.Neos:Document')) {
            $pageKeys[$this->pageKey($node)] = true;
        }

        foreach ($this->findChildNodes($node) as $childNode) {
            if (!$childNode->getNodeType()->isOfType('Neos.Neos:Document')) {
                continue;
            }

            $this->collectDocumentPageKeys($childNode, $pageKeys);
        }
    }

    /**
     * @return iterable<NodeInterface|TraversableNodeInterface>
     */
    private function findChildNodes(NodeInterface|TraversableNodeInterface $node): iterable
    {
        if (method_exists($node, 'getChildNodes')) {
            return $node->getChildNodes('Neos.Neos:Document');
        }

        return $node->findChildNodes();
    }

    private function pageKey(NodeInterface|TraversableNodeInterface $node): string
    {
        return (string)$node->findNodePath();
    }
}
