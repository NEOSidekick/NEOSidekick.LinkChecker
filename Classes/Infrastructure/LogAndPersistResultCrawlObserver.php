<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Client;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Throwable;

class LogAndPersistResultCrawlObserver extends CrawlObserver
{
    /**
     * @var ResultItemRepositoryInterface
     * @Flow\Inject
     */
    protected $resultItemRepository;

    /**
     * @var LinkStatusClassifier
     * @Flow\Inject
     */
    protected $linkStatusClassifier;

    /**
     * @var ConsoleOutput
     * @Flow\Inject
     */
    protected $output;

    /**
     * @Flow\InjectConfiguration(path="excludeStatusCodes")
     */
    protected array $excludeStatusCodes = [];

    protected array $resultItemsGroupedByStatusCode = [];

    /**
     * @var array<string, array<int, array{url: UriInterface, originUrl: UriInterface, statusCode: int}>>
     */
    protected array $pendingServerErrorResultsByTarget = [];

    private ?Client $revalidationClient = null;

    /**
     * Called when the crawl has ended.
     */
    public function finishedCrawling(): void
    {
        $this->persistPendingServerErrorResults();

        $this->outputLine('');
        $this->outputLine('Summary:');
        $this->outputLine('--------');

        if (count($this->resultItemsGroupedByStatusCode) === 0) {
            $this->outputLine('No links crawled. Maybe check on your robots index options.');
            return;
        }

        foreach ($this->resultItemsGroupedByStatusCode as $statusCode => $count) {
            if ($statusCode < 100) {
                $this->outputLine("$count url(s) did have unresponsive host(s)");
                continue;
            }

            $this->outputLine("Crawled $count url(s) with status code {$statusCode}");
        }
    }

    public function getErrorCount(): int
    {
        $errorCount = 0;
        foreach ($this->resultItemsGroupedByStatusCode as $count) {
            $errorCount += $count;
        }
        return $errorCount;
    }

    /**
     * Outputs specified text to the console window and appends a line break
     *
     * @see output()
     * @see outputLines()
     */
    protected function outputLine(string $text = '', array $arguments = []): void
    {
        $this->output->outputLine($text, $arguments);
    }

    /**
     * Called when the crawler has crawled the given url successfully.
     *
     * @param  UriInterface  $url
     * @param  ResponseInterface  $response
     * @param  UriInterface|null  $foundOnUrl
     * @param  string|null  $linkText
     * @return void
     */
    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $statusCode = $response->getStatusCode();
        if (!$this->isExcludedStatusCode($statusCode)) {
            $this->addCrawlingResultToStore($url, $foundOnUrl, $statusCode, $response->getHeaders());
        }
    }

    /**
     * Called when the crawler had a problem crawling the given url.
     * @param  UriInterface  $url
     * @param  RequestException  $requestException
     * @param  UriInterface|null  $foundOnUrl
     * @param  string|null  $linkText
     */
    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $response = $requestException->getResponse();
        $statusCode = $response instanceof ResponseInterface ? $response->getStatusCode() : (int)$requestException->getCode();
        $responseHeaders = $response instanceof ResponseInterface ? $response->getHeaders() : [];
        if (!$this->isExcludedStatusCode($statusCode)) {
            $this->addCrawlingResultToStore($url, $foundOnUrl, $statusCode, $responseHeaders);
        }
    }

    /**
     * We collect the crawling results in the class variable urlsGroupedByStatusCode.
     * We store the crawled url, the status code for this url and if an origin url exists also the location where
     * we got the crawling url from.
     */
    /**
     * @param array<string, array<int, string>> $responseHeaders
     */
    protected function addCrawlingResultToStore(
        UriInterface $crawlingUrl,
        ?UriInterface $originUrl = null,
        int $statusCode = 200,
        array $responseHeaders = []
    ): void {
        $cliMessage = "Checked {$crawlingUrl} from {$originUrl} with status {$statusCode}";
        if ($originUrl === null) {
            $cliMessage = "Checked {$crawlingUrl} with status {$statusCode}";
        }

        $this->outputLine($cliMessage);

        $state = $this->linkStatusClassifier->classify((string)$crawlingUrl, $statusCode, $responseHeaders);
        if ($state === ResultItem::STATE_OK) {
            return;
        }

        if ($originUrl === null) {
            throw new OriginUrlException('Origin url is null: ' . $cliMessage, 1668863280);
        }

        $parts = parse_url((string)$originUrl);

        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        // Server errors are frequently transient, so defer them for a single revalidation pass
        // before they are allowed to count as broken.
        if ($statusCode >= 500) {
            $this->pendingServerErrorResultsByTarget[(string)$crawlingUrl][] = [
                'url' => $crawlingUrl,
                'originUrl' => $originUrl,
                'statusCode' => $statusCode,
            ];
            return;
        }

        $this->persistCrawlingResult($crawlingUrl, $originUrl, $statusCode, $parts['host'], $state);
    }

    protected function persistPendingServerErrorResults(): void
    {
        if ($this->pendingServerErrorResultsByTarget === []) {
            return;
        }

        foreach ($this->pendingServerErrorResultsByTarget as $target => $pendingResults) {
            $finalStatusCode = $this->revalidateStatusCode($pendingResults[0]['url'], $pendingResults[0]['statusCode']);
            $this->outputLine(sprintf(
                'Revalidated %s from %s to %s',
                $target,
                $pendingResults[0]['statusCode'],
                $finalStatusCode
            ));

            if ($this->isExcludedStatusCode($finalStatusCode)) {
                continue;
            }

            $state = $this->linkStatusClassifier->classify((string)$pendingResults[0]['url'], $finalStatusCode);
            if ($state === ResultItem::STATE_OK) {
                continue;
            }

            foreach ($pendingResults as $pendingResult) {
                $parts = parse_url((string)$pendingResult['originUrl']);
                if ($parts === false || !isset($parts['host'])) {
                    continue;
                }

                $this->persistCrawlingResult(
                    $pendingResult['url'],
                    $pendingResult['originUrl'],
                    $finalStatusCode,
                    $parts['host'],
                    $state
                );
            }
        }

        $this->pendingServerErrorResultsByTarget = [];
    }

    protected function revalidateStatusCode(UriInterface $url, int $originalStatusCode): int
    {
        try {
            return $this->getRevalidationClient()
                ->request('GET', (string)$url)
                ->getStatusCode();
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
            if ($response instanceof ResponseInterface) {
                return $response->getStatusCode();
            }

            return (int)$exception->getCode();
        } catch (Throwable $exception) {
            return 0;
        }
    }

    private function getRevalidationClient(): Client
    {
        if ($this->revalidationClient === null) {
            $this->revalidationClient = new Client([
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => 10,
                RequestOptions::TIMEOUT => 30,
            ]);
        }

        return $this->revalidationClient;
    }

    private function persistCrawlingResult(
        UriInterface $crawlingUrl,
        UriInterface $originUrl,
        int $statusCode,
        string $domain,
        string $state = ResultItem::STATE_BROKEN
    ): void {
        $linkCheckItem = new ResultItem();
        $linkCheckItem->setDomain($domain);
        $linkCheckItem->setSourcePath((string)$originUrl);
        $linkCheckItem->setTarget((string)$crawlingUrl);
        $linkCheckItem->setStatusCode($statusCode);
        $linkCheckItem->setState($state);
        $linkCheckItem->setCreatedAt(new \DateTime());
        $linkCheckItem->setCheckedAt(new \DateTime());

        try {
            $this->resultItemRepository->add($linkCheckItem);
        } catch (IllegalObjectTypeException $e) {
            $this->outputLine("Could not persist entry for the url {$crawlingUrl}");
        }

        $this->resultItemsGroupedByStatusCode[$statusCode] = ($this->resultItemsGroupedByStatusCode[$statusCode] ?? 0) + 1;
    }

    /**
     * Determine if the status code concerns a successful or
     * redirect response.
     *
     * @param int|string $statusCode
     * @return bool
     */
    protected function isSuccessOrRedirect($statusCode): bool
    {
        return in_array((int)$statusCode, [200, 201, 301], true);
    }

    /**
     * Determine if the crawler saw some bad urls.
     */
    protected function crawledBadUrls(): bool
    {
        return collect($this->resultItemsGroupedByStatusCode)->keys()->filter(function ($statusCode) {
                return !$this->isSuccessOrRedirect($statusCode);
        })->count() > 0;
    }

    /**
     * Determine if the status code should be excluded'
     * from the reporter.
     *
     * @param int|string $statusCode
     * @return bool
     */
    protected function isExcludedStatusCode($statusCode): bool
    {
        return in_array($statusCode, $this->excludeStatusCodes, true);
    }
}
