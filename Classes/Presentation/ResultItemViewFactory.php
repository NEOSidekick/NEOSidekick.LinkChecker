<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Service\LinkingService;
use Neos\Neos\Service\UserService;

/**
 * @Flow\Scope("singleton")
 */
class ResultItemViewFactory
{
    /**
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var UserService
     * @Flow\Inject
     */
    protected $userService;

    /**
     * @var LinkingService
     * @Flow\Inject
     */
    protected $linkingService;

    public function create(ResultItem $resultItem, ControllerContext $controllerContext): ResultItemView
    {
        $sourcePath = $resultItem->getSourcePath() ?? '';
        $sourceFrontendUri = $this->createSourceFrontendUri($sourcePath, $resultItem->getDomain(), $controllerContext);
        $targetTitle = $this->resolveTargetPageTitle($resultItem->getTarget());

        return new ResultItemView(
            $resultItem,
            $this->createSourceLabel($sourcePath, $sourceFrontendUri),
            $sourceFrontendUri,
            $this->createSourceEditUri($sourcePath, $resultItem->getDomain(), $controllerContext),
            $targetTitle ?? $resultItem->getTarget(),
            $this->createTargetUri($resultItem, $controllerContext)
        );
    }

    private function createSourceLabel(string $sourcePath, string $sourceFrontendUri): string
    {
        if ($this->isNodePath($sourcePath)) {
            return str_replace('/', ' > ', trim(str_replace(['https://', 'http://'], '', $sourceFrontendUri), '/'));
        }

        if (str_starts_with($sourcePath, 'http')) {
            return preg_replace('#(https?://[^/]*)(/.*)#', '$2', $sourcePath) ?? $sourcePath;
        }

        return '#';
    }

    private function createSourceFrontendUri(string $sourcePath, string $domain, ControllerContext $controllerContext): string
    {
        if ($this->isNodePath($sourcePath)) {
            $sourceNode = $this->resolveNodeByPath($sourcePath, 'live');
            if ($sourceNode instanceof NodeInterface) {
                return $this->replaceUriHost(
                    $this->linkingService->createNodeUri($controllerContext, $sourceNode, null, null, true),
                    $domain
                );
            }

            return '#';
        }

        if (str_starts_with($sourcePath, 'http')) {
            return $sourcePath;
        }

        return '#';
    }

    private function createSourceEditUri(string $sourcePath, string $domain, ControllerContext $controllerContext): ?string
    {
        if (!$this->isNodePath($sourcePath)) {
            return null;
        }

        return $this->createBackendNodeUri($sourcePath, $controllerContext, $domain);
    }

    private function createTargetUri(ResultItem $resultItem, ControllerContext $controllerContext): ?string
    {
        $target = $resultItem->getTarget();

        if (str_starts_with($target, 'node://') && $resultItem->getTargetPath() !== null) {
            return $this->createBackendNodeUri($resultItem->getTargetPath(), $controllerContext, $resultItem->getDomain());
        }

        if (str_starts_with($target, 'http')) {
            return $target;
        }

        return null;
    }

    private function createBackendNodeUri(
        string $nodePath,
        ControllerContext $controllerContext,
        ?string $domain = null
    ): string {
        $workspaceName = $this->userService->getPersonalWorkspaceName() ?? 'live';
        $node = $this->resolveNodeByPath($nodePath, $workspaceName);
        if (!($node instanceof NodeInterface)) {
            return '#';
        }

        $uri = $this->createBackendContentUri($controllerContext, $node->getContextPath());

        if ($domain !== null) {
            return $this->replaceUriHost($uri, $domain);
        }

        return $uri;
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

    private function resolveNodeByPath(string $nodePath, string $workspaceName): ?NodeInterface
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
            'removedContentShown' => false,
        ]);

        return $context->getNode($nodePath);
    }

    private function replaceUriHost(string $uri, string $domain): string
    {
        if ($domain === '' || $uri === '#') {
            return $uri;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $uri;
        }

        $host = $parts['host'];
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        return preg_replace(
            '#^' . preg_quote($parts['scheme'] . '://' . $host, '#') . '#',
            $parts['scheme'] . '://' . $domain,
            $uri
        ) ?? $uri;
    }

    private function resolveTargetPageTitle(string $target): ?string
    {
        preg_match(LinkingService::PATTERN_SUPPORTED_URIS, $target, $matches);
        $nodeIdentifier = $matches[2] ?? null;

        if ($nodeIdentifier === null) {
            return null;
        }

        $baseContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);
        $targetNode = $baseContext->getNodeByIdentifier($nodeIdentifier);

        if (!($targetNode instanceof NodeInterface)) {
            return null;
        }

        $title = $targetNode->getProperty('title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    private function isNodePath(string $sourcePath): bool
    {
        return str_starts_with($sourcePath, '/sites');
    }
}
