<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;

/**
 * @Flow\Scope("singleton")
 */
class ResultItemViewFactory
{
    private const NODE_LABEL_PROPERTIES = [
        'title',
        'titleOverride',
        'heroTitle',
        'uriPathSegment',
    ];

    private array $nodeLabelsByPath = [];

    private array $nodeLabelsByIdentifier = [];

    private $liveContext = null;

    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var NodeDataRepository
     * @Flow\Inject
     */
    protected $nodeDataRepository;

    public function create(ResultItem $resultItem, ControllerContext $controllerContext): ResultItemView
    {
        $sourcePath = $resultItem->getSourcePath() ?? '';
        $sourceFrontendUri = $this->createSourceFrontendUri($sourcePath, $resultItem->getDomain(), $controllerContext);
        $sourceLabel = $this->createSourceLabel($sourcePath, $sourceFrontendUri);
        $targetLabel = $this->createTargetLabel($resultItem);

        return new ResultItemView(
            $resultItem,
            $sourceLabel,
            $sourceFrontendUri,
            $this->createSourceEditUri($sourcePath, $controllerContext),
            $targetLabel,
            $this->createTargetUri($resultItem, $controllerContext)
        );
    }

    private function createSourceLabel(string $sourcePath, string $sourceFrontendUri): string
    {
        if ($this->isNodePath($sourcePath)) {
            return $this->resolveNodeLabelByPath($sourcePath)
                ?? str_replace('/', ' > ', trim(str_replace(['https://', 'http://'], '', $sourceFrontendUri), '/'));
        }

        if (str_starts_with($sourcePath, 'http')) {
            return preg_replace('#(https?://[^/]*)(/.*)#', '$2', $sourcePath) ?? $sourcePath;
        }

        return '#';
    }

    private function createTargetLabel(ResultItem $resultItem): string
    {
        if ($resultItem->getTargetPath() !== null) {
            $label = $this->resolveNodeLabelByPath($resultItem->getTargetPath());
            if ($label !== null) {
                return $label;
            }
        }

        if (str_starts_with($resultItem->getTarget(), 'node://')) {
            $label = $this->resolveNodeLabelByIdentifier(substr($resultItem->getTarget(), 7));
            if ($label !== null) {
                return $label;
            }
        }

        return $resultItem->getTargetPath() ?? $resultItem->getTarget();
    }

    private function createSourceFrontendUri(string $sourcePath, string $domain, ControllerContext $controllerContext): string
    {
        if ($this->isNodePath($sourcePath)) {
            return $this->createFallbackSourceFrontendUri($sourcePath, $domain, $controllerContext);
        }

        if (str_starts_with($sourcePath, 'http')) {
            return $sourcePath;
        }

        return '#';
    }

    private function createFallbackSourceFrontendUri(
        string $sourcePath,
        string $domain,
        ControllerContext $controllerContext
    ): string {
        if ($domain === '') {
            return '#';
        }

        $path = preg_replace('#^/sites/[^/]+#', '', $sourcePath) ?? '';
        $path = preg_replace('#@.*$#', '', $path) ?? '';
        $path = '/' . ltrim($path, '/');

        $requestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        return $requestUri->getScheme() . '://' . $domain . ($path === '/' ? '/' : $path);
    }

    private function createSourceEditUri(string $sourcePath, ControllerContext $controllerContext): ?string
    {
        if (!$this->isNodePath($sourcePath)) {
            return null;
        }

        return $this->createBackendContentUri($controllerContext, $sourcePath);
    }

    private function createTargetUri(ResultItem $resultItem, ControllerContext $controllerContext): ?string
    {
        $target = $resultItem->getTarget();

        if (str_starts_with($target, 'node://') && $resultItem->getTargetPath() !== null) {
            return $this->createBackendContentUri($controllerContext, $resultItem->getTargetPath());
        }

        if (str_starts_with($target, 'http')) {
            return $target;
        }

        return null;
    }

    private function createBackendContentUri(ControllerContext $controllerContext, string $nodeContextPath): string
    {
        $requestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        $authority = $requestUri->getHost();
        if ($requestUri->getPort() !== null) {
            $authority .= ':' . $requestUri->getPort();
        }

        return $requestUri->getScheme() . '://' . $authority . '/neos/content?' . http_build_query([
            'node' => $nodeContextPath,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function resolveNodeLabelByPath(string $nodePath): ?string
    {
        if (array_key_exists($nodePath, $this->nodeLabelsByPath)) {
            return $this->nodeLabelsByPath[$nodePath];
        }

        $nodeData = $this->nodeDataRepository->findOneByPath(
            $nodePath,
            $this->getLiveContext()->getWorkspace(false),
            $this->getLiveContext()->getDimensions(),
            null
        );

        return $this->nodeLabelsByPath[$nodePath] = $this->extractNodeLabel($nodeData);
    }

    private function resolveNodeLabelByIdentifier(string $nodeIdentifier): ?string
    {
        if (array_key_exists($nodeIdentifier, $this->nodeLabelsByIdentifier)) {
            return $this->nodeLabelsByIdentifier[$nodeIdentifier];
        }

        if ($nodeIdentifier === '') {
            return $this->nodeLabelsByIdentifier[$nodeIdentifier] = null;
        }

        $nodeData = $this->nodeDataRepository->findOneByIdentifier(
            $nodeIdentifier,
            $this->getLiveContext()->getWorkspace(false),
            $this->getLiveContext()->getDimensions(),
            null
        );

        return $this->nodeLabelsByIdentifier[$nodeIdentifier] = $this->extractNodeLabel($nodeData);
    }

    private function extractNodeLabel(?NodeData $nodeData): ?string
    {
        if ($nodeData === null) {
            return null;
        }

        foreach (self::NODE_LABEL_PROPERTIES as $propertyName) {
            $propertyValue = $nodeData->getProperty($propertyName);
            if (is_string($propertyValue) && trim($propertyValue) !== '') {
                return trim($propertyValue);
            }
        }

        return $nodeData->getPath();
    }

    private function getLiveContext()
    {
        return $this->liveContext ??= $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
            'removedContentShown' => true,
        ]);
    }

    private function isNodePath(string $sourcePath): bool
    {
        return str_starts_with($sourcePath, '/sites');
    }
}
