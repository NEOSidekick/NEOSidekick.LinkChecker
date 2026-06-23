<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Infrastructure\LinkStatusClassifier;
use NEOSidekick\LinkChecker\Infrastructure\LogAndPersistResultCrawlObserver;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Psr\Http\Message\UriInterface;

class LogAndPersistResultCrawlObserverTest extends UnitTestCase
{
    /** @test */
    public function transientServerErrorThatRecoversToSuccessIsSkipped(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, ['https://target.example/flaky' => 200]);

        $observer->crawled(new Uri('https://target.example/flaky'), new Response(500), new Uri('https://example.com/source'));
        $observer->finishedCrawling();

        self::assertCount(0, $repository->addedResultItems);
        self::assertSame(0, $observer->getErrorCount());
    }

    /** @test */
    public function transientServerErrorThatRecoversToExcludedRedirectIsSkipped(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, ['https://target.example/flaky' => 302]);

        $observer->crawled(new Uri('https://target.example/flaky'), new Response(500), new Uri('https://example.com/source'));
        $observer->finishedCrawling();

        self::assertCount(0, $repository->addedResultItems);
        self::assertSame(0, $observer->getErrorCount());
    }

    /** @test */
    public function stableServerErrorIsPersisted(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, ['https://target.example/still-broken' => 500]);

        $observer->crawled(new Uri('https://target.example/still-broken'), new Response(500), new Uri('https://example.com/source'));
        $observer->finishedCrawling();

        self::assertCount(1, $repository->addedResultItems);
        self::assertSame(500, $repository->addedResultItems[0]->getStatusCode());
        self::assertSame(1, $observer->getErrorCount());
    }

    /** @test */
    public function serverErrorThatBecomesClientErrorIsPersistedWithFinalStatusCode(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, ['https://target.example/missing' => 404]);

        $observer->crawled(new Uri('https://target.example/missing'), new Response(500), new Uri('https://example.com/source'));
        $observer->finishedCrawling();

        self::assertCount(1, $repository->addedResultItems);
        self::assertSame(404, $repository->addedResultItems[0]->getStatusCode());
        self::assertSame(1, $observer->getErrorCount());
    }

    /** @test */
    public function forbiddenResponseIsPersistedAsWarningNotBroken(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, []);

        $observer->crawled(new Uri('https://target.example/members-only'), new Response(403), new Uri('https://example.com/source'));
        $observer->finishedCrawling();

        self::assertCount(1, $repository->addedResultItems);
        self::assertSame(403, $repository->addedResultItems[0]->getStatusCode());
        self::assertSame(ResultItem::STATE_WARNING, $repository->addedResultItems[0]->getState());
        self::assertFalse($repository->addedResultItems[0]->isBroken());
    }

    /** @test */
    public function notFoundResponseIsPersistedAsBroken(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, []);

        $observer->crawled(new Uri('https://target.example/missing'), new Response(404), new Uri('https://example.com/source'));
        $observer->finishedCrawling();

        self::assertCount(1, $repository->addedResultItems);
        self::assertSame(ResultItem::STATE_BROKEN, $repository->addedResultItems[0]->getState());
        self::assertTrue($repository->addedResultItems[0]->isBroken());
    }

    /** @test */
    public function resolvedSameSiteUrlsArePersistedAsInternalNodeReferences(): void
    {
        $repository = new InMemoryResultItemRepository();
        $observer = $this->createObserver($repository, [], [
            'https://example.com/source-page' => [
                'identifier' => 'source-node-id',
                'path' => '/sites/site/source-page',
            ],
            'https://example.com/hidden-parent/hidden-target' => [
                'identifier' => 'target-node-id',
                'path' => '/sites/site/hidden-parent/hidden-target',
            ],
        ]);

        $observer->crawled(
            new Uri('https://example.com/hidden-parent/hidden-target'),
            new Response(404),
            new Uri('https://example.com/source-page')
        );
        $observer->finishedCrawling();

        self::assertCount(1, $repository->addedResultItems);
        self::assertSame('source-node-id', $repository->addedResultItems[0]->getSource());
        self::assertSame('/sites/site/source-page', $repository->addedResultItems[0]->getSourcePath());
        self::assertSame('node://target-node-id', $repository->addedResultItems[0]->getTarget());
        self::assertSame('/sites/site/hidden-parent/hidden-target', $repository->addedResultItems[0]->getTargetPath());
    }

    /** @test */
    public function targetUrlsOnAnotherConfiguredDomainResolveAgainstThatDomainsSite(): void
    {
        $siteA = new Site('site-a');
        $domainA = new Domain();
        $domainA->setHostname('a.example.com');
        $domainA->setSite($siteA);

        $siteB = new Site('site-b');
        $domainB = new Domain();
        $domainB->setHostname('b.example.com');
        $domainB->setSite($siteB);

        $targetNode = $this->createDocumentNode('hidden-target', 'target-node-id', '/sites/site-b/hidden-target');
        $siteBNode = $this->createDocumentNode('site-b', 'site-b-node-id', '/sites/site-b', [$targetNode]);

        $contextB = $this->getMockBuilder(ContentContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentSiteNode'])
            ->getMock();
        $contextB->method('getCurrentSiteNode')->willReturn($siteBNode);

        $contextFactory = $this->createMock(ContextFactoryInterface::class);
        $contextFactory->method('create')->willReturn($contextB);

        $domainRepository = $this->getMockBuilder(DomainRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneByHost'])
            ->getMock();
        $domainRepository
            ->expects(self::once())
            ->method('findOneByHost')
            ->with('b.example.com', true)
            ->willReturn($domainB);

        $observer = new ResolvingLogAndPersistResultCrawlObserver();
        $observer->setCrawledDomain($domainA);
        $this->inject($observer, 'contextFactory', $contextFactory);
        $this->inject($observer, 'domainRepository', $domainRepository);

        $resolvedNode = $observer->resolve(new Uri('https://b.example.com/hidden-target'));

        self::assertSame([
            'identifier' => 'target-node-id',
            'path' => '/sites/site-b/hidden-target',
        ], $resolvedNode);
    }

    /** @test */
    public function singleSegmentUrlPathCanResolveToHiddenDocumentTitle(): void
    {
        $site = new Site('site');
        $domain = new Domain();
        $domain->setHostname('example.com');
        $domain->setSite($site);

        $targetNode = $this->createDocumentNode(
            'elternseite-ist-versteckt',
            'target-node-id',
            '/sites/site/hidden-parent/hidden-target',
            [],
            ['title' => 'Elternseite ist versteckt']
        );
        $hiddenParentNode = $this->createDocumentNode(
            'versteckte-seite',
            'hidden-parent-id',
            '/sites/site/hidden-parent',
            [$targetNode],
            ['title' => 'Versteckte Seite']
        );
        $siteNode = $this->createDocumentNode('site', 'site-node-id', '/sites/site', [$hiddenParentNode]);

        $context = $this->getMockBuilder(ContentContext::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentSiteNode'])
            ->getMock();
        $context->method('getCurrentSiteNode')->willReturn($siteNode);

        $contextFactory = $this->createMock(ContextFactoryInterface::class);
        $contextFactory->method('create')->willReturn($context);

        $observer = new ResolvingLogAndPersistResultCrawlObserver();
        $observer->setCrawledDomain($domain);
        $this->inject($observer, 'contextFactory', $contextFactory);

        $resolvedNode = $observer->resolve(new Uri('https://example.com/Elternseite%20ist%20versteckt'));

        self::assertSame([
            'identifier' => 'target-node-id',
            'path' => '/sites/site/hidden-parent/hidden-target',
        ], $resolvedNode);
    }

    private function createObserver(
        InMemoryResultItemRepository $repository,
        array $revalidatedStatusCodes,
        array $resolvedNodesByUrl = []
    ): TestableLogAndPersistResultCrawlObserver {
        $observer = new TestableLogAndPersistResultCrawlObserver($revalidatedStatusCodes, $resolvedNodesByUrl);
        $output = $this->getMockBuilder(ConsoleOutput::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['outputLine'])
            ->getMock();

        $classifier = new LinkStatusClassifier();
        $this->inject($classifier, 'warningStatusCodes', [401, 403, 429]);
        $this->inject($classifier, 'detectCloudflareChallenge', true);
        $this->inject($classifier, 'knownBlockerDomains', []);
        $this->inject($classifier, 'ignoreRules', []);

        $this->inject($observer, 'resultItemRepository', $repository);
        $this->inject($observer, 'linkStatusClassifier', $classifier);
        $this->inject($observer, 'output', $output);
        $this->inject($observer, 'excludeStatusCodes', [0, 301, 302, 303, 307, 308]);

        return $observer;
    }

    /**
     * @param array<NodeInterface> $childNodes
     */
    private function createDocumentNode(
        string $uriPathSegment,
        string $identifier,
        string $path,
        array $childNodes = [],
        array $properties = []
    ): NodeInterface
    {
        $nodeType = $this->getMockBuilder(NodeType::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isOfType'])
            ->getMock();
        $nodeType->method('isOfType')->with('Neos.Neos:Document')->willReturn(true);

        $properties = ['uriPathSegment' => $uriPathSegment] + $properties;

        $node = $this->createMock(NodeInterface::class);
        $node->method('getNodeType')->willReturn($nodeType);
        $node->method('getProperty')->willReturnCallback(static fn (string $propertyName) => $properties[$propertyName] ?? null);
        $node->method('getIdentifier')->willReturn($identifier);
        $node->method('getPath')->willReturn($path);
        $node->method('getChildNodes')->with('Neos.Neos:Document')->willReturn($childNodes);

        return $node;
    }
}

class TestableLogAndPersistResultCrawlObserver extends LogAndPersistResultCrawlObserver
{
    public function __construct(
        private readonly array $revalidatedStatusCodes,
        private readonly array $resolvedNodesByUrl = []
    )
    {
    }

    protected function revalidateStatusCode(UriInterface $url, int $originalStatusCode): int
    {
        return $this->revalidatedStatusCodes[(string)$url] ?? $originalStatusCode;
    }

    protected function resolveInternalNodeUrl(UriInterface $url): ?array
    {
        return $this->resolvedNodesByUrl[(string)$url] ?? null;
    }
}

class ResolvingLogAndPersistResultCrawlObserver extends LogAndPersistResultCrawlObserver
{
    public function resolve(UriInterface $url): ?array
    {
        return $this->resolveInternalNodeUrl($url);
    }
}

class InMemoryResultItemRepository implements ResultItemRepositoryInterface
{
    /**
     * @var ResultItem[]
     */
    public array $addedResultItems = [];

    public function findAll(): QueryResultInterface
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function findFirstNonIgnored(int $limit): QueryResultInterface
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function findFilteredNonIgnored(
        int $limit,
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact
    ): QueryResultInterface
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function countFilteredNonIgnored(string $targetType, string $domain, string $statusCode, string $impact): int
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function remove(ResultItem $resultItem): void
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function findByDomainTargetAndStatusCode(string $domain, string $target, int $statusCode): array
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function truncate(): void
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function removeAllNonIgnored(): void
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function ignore(ResultItem $resultItem): void
    {
        throw new \BadMethodCallException('Not implemented for this test.');
    }

    public function add(ResultItem $resultItem): void
    {
        $this->addedResultItems[] = $resultItem;
    }
}
