<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Presentation;

use DateTimeImmutable;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Presentation\ResultItemViewFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Service\LinkingService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class ResultItemViewFactoryTest extends UnitTestCase
{
    /** @test */
    public function sourceNodePathUsesResolvedNodeTitleAsLabel(): void
    {
        $factory = $this->createFactory([
            '/sites/lab/source-page' => $this->createNodeData(['title' => 'Wählen FAQ'], '/sites/lab/source-page'),
        ]);

        $view = $factory->create(
            $this->createResultItem('/sites/lab/source-page', 'https://example.com/missing', null),
            $this->createControllerContext()
        );

        self::assertSame('Wählen FAQ', $view->getSourceLabel());
    }

    /** @test */
    public function nodeTargetUsesResolvedTargetTitleAsLabel(): void
    {
        $factory = $this->createFactory([
            '/sites/lab/source-page' => $this->createNodeData(['title' => 'Wählen FAQ'], '/sites/lab/source-page'),
            '/sites/lab/hidden-target' => $this->createNodeData(['title' => 'Glossar'], '/sites/lab/hidden-target'),
        ]);

        $view = $factory->create(
            $this->createResultItem('/sites/lab/source-page', 'node://target-node-id', '/sites/lab/hidden-target'),
            $this->createControllerContext()
        );

        self::assertSame('Glossar', $view->getTargetLabel());
    }

    /** @test */
    public function resolvedUrlTargetUsesTargetPathForBackendUri(): void
    {
        $factory = $this->createFactory([
            '/sites/lab/hidden-target' => $this->createNodeData(['title' => 'Glossar'], '/sites/lab/hidden-target'),
        ]);

        $view = $factory->create(
            $this->createResultItem('/sites/lab/source-page', 'https://lab.neoseu.ddev.site/hidden-target', '/sites/lab/hidden-target'),
            $this->createControllerContext()
        );

        self::assertSame('Glossar', $view->getTargetLabel());
        self::assertSame('https://lab.neoseu.ddev.site/neos/content?node=%2Fsites%2Flab%2Fhidden-target', $view->getTargetUri());
    }

    /** @test */
    public function externalCrawlerSourceKeepsStoredUrlPathAsLabel(): void
    {
        $factory = $this->createFactory([]);

        $view = $factory->create(
            $this->createResultItem(
                'http://lab.neoseu.ddev.site/blog/2025/11/was-bringt-die-uno-klimakonferenz',
                'https://example.com/missing',
                null
            ),
            $this->createControllerContext()
        );

        self::assertSame('/blog/2025/11/was-bringt-die-uno-klimakonferenz', $view->getSourceLabel());
    }

    /** @test */
    public function sourceNodePathUsesRoutedFrontendUriOnResultDomain(): void
    {
        $factory = $this->createFactory(
            [
                '/sites/beatemeinl/source-page' => $this->createNodeData(['title' => 'Die EU - Einfach Unnötig'], '/sites/beatemeinl/source-page'),
            ],
            [
                '/sites/beatemeinl/source-page@live' => '/die-eu/einfach-unnoetig',
            ]
        );

        $view = $factory->create(
            $this->createResultItem(
                '/sites/beatemeinl/source-page',
                'https://example.com/missing',
                null,
                'beatemeinl.neoseu.ddev.site'
            ),
            $this->createControllerContext()
        );

        self::assertSame('https://beatemeinl.neoseu.ddev.site/die-eu/einfach-unnoetig', $view->getSourceFrontendUri());
        self::assertSame(
            'https://beatemeinl.neoseu.ddev.site/neos/content?node=%2Fsites%2Fbeatemeinl%2Fsource-page',
            $view->getSourceEditUri()
        );
    }

    /** @test */
    public function internalTargetEditUriUsesResultDomain(): void
    {
        $factory = $this->createFactory([
            '/sites/beatemeinl/source-page' => $this->createNodeData(['title' => 'Die EU - Einfach Unnötig'], '/sites/beatemeinl/source-page'),
            '/sites/beatemeinl/hidden-target' => $this->createNodeData(['title' => 'Hidden Target'], '/sites/beatemeinl/hidden-target'),
        ]);

        $view = $factory->create(
            $this->createResultItem(
                '/sites/beatemeinl/source-page',
                'node://target-node-id',
                '/sites/beatemeinl/hidden-target',
                'beatemeinl.neoseu.ddev.site'
            ),
            $this->createControllerContext()
        );

        self::assertSame(
            'https://beatemeinl.neoseu.ddev.site/neos/content?node=%2Fsites%2Fbeatemeinl%2Fhidden-target',
            $view->getTargetUri()
        );
    }

    /**
     * @param array<string, NodeData> $nodeDataByPath
     * @param array<string, string> $nodeUrisByContextPath
     */
    private function createFactory(array $nodeDataByPath, array $nodeUrisByContextPath = []): ResultItemViewFactory
    {
        $workspace = $this->createMock(Workspace::class);

        $context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorkspace', 'getDimensions'])
            ->getMock();
        $context->method('getWorkspace')->with(false)->willReturn($workspace);
        $context->method('getDimensions')->willReturn([]);

        $contextFactory = $this->createMock(ContextFactoryInterface::class);
        $contextFactory->method('create')->willReturn($context);

        $nodeDataRepository = $this->getMockBuilder(NodeDataRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneByPath', 'findOneByIdentifier'])
            ->getMock();
        $nodeDataRepository
            ->method('findOneByPath')
            ->willReturnCallback(static fn (string $path) => $nodeDataByPath[$path] ?? null);
        $nodeDataRepository
            ->method('findOneByIdentifier')
            ->willReturnCallback(static function (string $identifier) use ($nodeDataByPath) {
                foreach ($nodeDataByPath as $nodeData) {
                    if ($nodeData->getIdentifier() === $identifier) {
                        return $nodeData;
                    }
                }

                return null;
            });

        $linkingService = $this->getMockBuilder(LinkingService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createNodeUri'])
            ->getMock();
        $linkingService
            ->method('createNodeUri')
            ->willReturnCallback(static fn (ControllerContext $controllerContext, string $node) => $nodeUrisByContextPath[$node] ?? '/');

        $factory = new ResultItemViewFactory();
        $this->inject($factory, 'contextFactory', $contextFactory);
        $this->inject($factory, 'nodeDataRepository', $nodeDataRepository);
        $this->inject($factory, 'linkingService', $linkingService);

        return $factory;
    }

    /**
     * @param array<string, mixed> $properties
     * @return NodeData&MockObject
     */
    private function createNodeData(array $properties, string $path, string $identifier = 'target-node-id'): NodeData
    {
        $nodeData = $this->getMockBuilder(NodeData::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProperty', 'getPath', 'getIdentifier'])
            ->getMock();
        $nodeData
            ->method('getProperty')
            ->willReturnCallback(static fn (string $propertyName) => $properties[$propertyName] ?? null);
        $nodeData->method('getPath')->willReturn($path);
        $nodeData->method('getIdentifier')->willReturn($identifier);

        return $nodeData;
    }

    private function createResultItem(
        string $sourcePath,
        string $target,
        ?string $targetPath,
        string $domain = 'lab.neoseu.ddev.site'
    ): ResultItem
    {
        $resultItem = new ResultItem();
        $resultItem->setDomain($domain);
        $resultItem->setSourcePath($sourcePath);
        $resultItem->setTarget($target);
        $resultItem->setTargetPath($targetPath);
        $resultItem->setStatusCode(404);
        $resultItem->setCreatedAt(new DateTimeImmutable('2026-06-19 12:00:00'));
        $resultItem->setCheckedAt(new DateTimeImmutable('2026-06-19 12:00:00'));

        return $resultItem;
    }

    private function createControllerContext(): ControllerContext
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('neoswebsite.ddev.site');
        $uri->method('getPort')->willReturn(null);

        $httpRequest = $this->createMock(ServerRequestInterface::class);
        $httpRequest->method('getUri')->willReturn($uri);

        $actionRequest = $this->getMockBuilder(ActionRequest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getHttpRequest'])
            ->getMock();
        $actionRequest->method('getHttpRequest')->willReturn($httpRequest);

        return new ControllerContext(
            $actionRequest,
            new ActionResponse(),
            new Arguments(),
            new UriBuilder()
        );
    }
}
