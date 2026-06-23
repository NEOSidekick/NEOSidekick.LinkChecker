<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Service\LinkingService;
use Throwable;

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

    /**
     * @var LinkingService
     * @Flow\Inject
     */
    protected $linkingService;

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
            $this->createSourceEditUri($sourcePath, $resultItem->getDomain(), $controllerContext),
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
        $resolvedUri = $this->createResolvedSourceFrontendUri($sourcePath, $domain, $controllerContext);
        if ($resolvedUri !== null) {
            return $resolvedUri;
        }

        if ($domain === '') {
            return '#';
        }

        $path = preg_replace('#^/sites/[^/]+#', '', $sourcePath) ?? '';
        $path = preg_replace('#@.*$#', '', $path) ?? '';
        $path = '/' . ltrim($path, '/');

        $requestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        return $requestUri->getScheme() . '://' . $domain . ($path === '/' ? '/' : $path);
    }

    private function createResolvedSourceFrontendUri(
        string $sourcePath,
        string $domain,
        ControllerContext $controllerContext
    ): ?string {
        if ($domain === '') {
            return null;
        }

        try {
            $uri = $this->linkingService->createNodeUri(
                $controllerContext,
                $this->createLiveNodeContextPath($sourcePath),
                null,
                null,
                false
            );
        } catch (Throwable) {
            return null;
        }

        if ($uri === '') {
            return null;
        }

        return $this->createAbsoluteUri($controllerContext, $domain, $uri);
    }

    private function createSourceEditUri(
        string $sourcePath,
        string $domain,
        ControllerContext $controllerContext
    ): ?string
    {
        if (!$this->isNodePath($sourcePath)) {
            return null;
        }

        return $this->createBackendContentUri($controllerContext, $sourcePath, $domain);
    }

    private function createTargetUri(ResultItem $resultItem, ControllerContext $controllerContext): ?string
    {
        $target = $resultItem->getTarget();

        if ($resultItem->getTargetPath() !== null) {
            return $this->createBackendContentUri($controllerContext, $resultItem->getTargetPath(), $resultItem->getDomain());
        }

        if (str_starts_with($target, 'http')) {
            return $target;
        }

        return null;
    }

    private function createBackendContentUri(
        ControllerContext $controllerContext,
        string $nodeContextPath,
        string $domain
    ): string {
        return $this->createAbsoluteUri($controllerContext, $domain, '/neos/content?' . http_build_query([
            'node' => $nodeContextPath,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    private function createAbsoluteUri(ControllerContext $controllerContext, string $domain, string $pathAndQuery): string
    {
        $requestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        $authority = $domain !== '' ? $domain : $requestUri->getHost();
        if (!str_contains($authority, ':') && $requestUri->getPort() !== null) {
            $authority .= ':' . $requestUri->getPort();
        }

        if (str_starts_with($pathAndQuery, 'http://') || str_starts_with($pathAndQuery, 'https://')) {
            $path = parse_url($pathAndQuery, PHP_URL_PATH) ?: '/';
            $query = parse_url($pathAndQuery, PHP_URL_QUERY);
            $pathAndQuery = $path . ($query !== null ? '?' . $query : '');
        }

        return $requestUri->getScheme() . '://' . $authority . '/' . ltrim($pathAndQuery, '/');
    }

    private function createLiveNodeContextPath(string $nodePath): string
    {
        if (str_contains($nodePath, '@')) {
            return $nodePath;
        }

        return $nodePath . '@live';
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
