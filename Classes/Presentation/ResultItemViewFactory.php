<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
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

    public function create(ResultItem $resultItem, ControllerContext $controllerContext): ResultItemView
    {
        $sourcePath = $resultItem->getSourcePath() ?? '';
        $sourceFrontendUri = $this->createSourceFrontendUri($sourcePath, $controllerContext);
        $targetTitle = $this->resolveTargetPageTitle($resultItem->getTarget());

        return new ResultItemView(
            $resultItem,
            $this->createSourceLabel($sourcePath, $sourceFrontendUri),
            $sourceFrontendUri,
            $this->createSourceEditUri($sourcePath, $controllerContext),
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

    private function createSourceFrontendUri(string $sourcePath, ControllerContext $controllerContext): string
    {
        if ($this->isNodePath($sourcePath)) {
            return $this->createUriBuilder($controllerContext)
                ->uriFor('show', ['node' => $sourcePath], 'Frontend\Node', 'Neos.Neos');
        }

        if (str_starts_with($sourcePath, 'http')) {
            return $sourcePath;
        }

        return '#';
    }

    private function createSourceEditUri(string $sourcePath, ControllerContext $controllerContext): ?string
    {
        if (!$this->isNodePath($sourcePath)) {
            return null;
        }

        return $this->createBackendNodeUri($sourcePath, $controllerContext);
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
        $uri = $this->createUriBuilder($controllerContext)
            ->uriFor('index', ['node' => $nodePath . '@' . $workspaceName], 'Backend', 'Neos.Neos.Ui');

        if ($domain !== null) {
            return str_replace((string)$controllerContext->getRequest()->getHttpRequest()->getUri(), $domain, $uri);
        }

        return $uri;
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

    private function createUriBuilder(ControllerContext $controllerContext): UriBuilder
    {
        return (clone $controllerContext->getUriBuilder())->reset();
    }

    private function isNodePath(string $sourcePath): bool
    {
        return str_starts_with($sourcePath, '/sites');
    }
}
