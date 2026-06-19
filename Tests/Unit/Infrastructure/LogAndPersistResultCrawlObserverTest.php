<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Infrastructure\LogAndPersistResultCrawlObserver;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Tests\UnitTestCase;
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

    private function createObserver(
        InMemoryResultItemRepository $repository,
        array $revalidatedStatusCodes
    ): TestableLogAndPersistResultCrawlObserver {
        $observer = new TestableLogAndPersistResultCrawlObserver($revalidatedStatusCodes);
        $output = $this->getMockBuilder(ConsoleOutput::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['outputLine'])
            ->getMock();

        $this->inject($observer, 'resultItemRepository', $repository);
        $this->inject($observer, 'output', $output);
        $this->inject($observer, 'excludeStatusCodes', [0, 301, 302, 303, 307, 308]);

        return $observer;
    }
}

class TestableLogAndPersistResultCrawlObserver extends LogAndPersistResultCrawlObserver
{
    public function __construct(private readonly array $revalidatedStatusCodes)
    {
    }

    protected function revalidateStatusCode(UriInterface $url, int $originalStatusCode): int
    {
        return $this->revalidatedStatusCodes[(string)$url] ?? $originalStatusCode;
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
