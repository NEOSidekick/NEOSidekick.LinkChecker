<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Client;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\DomainRepository;
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
     * @var ContextFactoryInterface
     * @Flow\Inject
     */
    protected $contextFactory;

    /**
     * @var DomainRepository
     * @Flow\Inject
     */
    protected $domainRepository;

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

    private ?Domain $crawledDomain = null;

    private array $hiddenContentContextsByHost = [];

    public function setCrawledDomain(Domain $domain): void
    {
        $this->crawledDomain = $domain;
        $this->hiddenContentContextsByHost = [];
    }

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
        $sourceNode = $this->resolveInternalNodeUrl($originUrl);
        $targetNode = $this->resolveInternalNodeUrl($crawlingUrl);

        $linkCheckItem = new ResultItem();
        $linkCheckItem->setDomain($domain);
        $linkCheckItem->setSource($sourceNode['identifier'] ?? null);
        $linkCheckItem->setSourcePath($sourceNode['path'] ?? (string)$originUrl);
        if ($targetNode !== null) {
            $linkCheckItem->setTarget('node://' . $targetNode['identifier']);
            $linkCheckItem->setTargetPath($targetNode['path']);
        } else {
            $linkCheckItem->setTarget((string)$crawlingUrl);
        }
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
     * @return array{identifier: string, path: string}|null
     */
    protected function resolveInternalNodeUrl(UriInterface $url): ?array
    {
        $domain = $this->findInternalDomainForUrl($url);
        if ($domain === null) {
            return null;
        }

        if (!in_array(strtolower($url->getScheme()), ['http', 'https'], true)) {
            return null;
        }

        try {
            $node = $this->findNodeByUriPath($this->getHiddenContentContext($domain)->getCurrentSiteNode(), $url->getPath());
        } catch (Throwable) {
            return null;
        }

        if ($node === null) {
            return null;
        }

        $identifier = method_exists($node, 'getNodeAggregateIdentifier')
            ? (string)$node->getNodeAggregateIdentifier()
            : (method_exists($node, 'getIdentifier') ? (string)$node->getIdentifier() : '');
        $path = method_exists($node, 'findNodePath')
            ? (string)$node->findNodePath()
            : (method_exists($node, 'getPath') ? (string)$node->getPath() : '');

        if ($identifier === '' || $path === '') {
            return null;
        }

        return [
            'identifier' => $identifier,
            'path' => $path,
        ];
    }

    private function findInternalDomainForUrl(UriInterface $url): ?Domain
    {
        $host = strtolower($url->getHost());
        if ($host === '') {
            return null;
        }

        if ($this->crawledDomain !== null && $host === strtolower($this->crawledDomain->getHostname())) {
            return $this->crawledDomain;
        }

        if (!$this->domainRepository instanceof DomainRepository) {
            return null;
        }

        return $this->domainRepository->findOneByHost($host, true);
    }

    private function getHiddenContentContext(Domain $domain)
    {
        $host = strtolower($domain->getHostname());
        if (!isset($this->hiddenContentContextsByHost[$host])) {
            $this->hiddenContentContextsByHost[$host] = $this->contextFactory->create([
                'workspaceName' => 'live',
                'currentSite' => $domain->getSite(),
                'currentDomain' => $domain,
                'invisibleContentShown' => true,
                'inaccessibleContentShown' => true,
                'removedContentShown' => true,
            ]);
        }

        return $this->hiddenContentContextsByHost[$host];
    }

    private function findNodeByUriPath($siteNode, string $uriPath)
    {
        $node = $siteNode;
        $relativeUriPath = trim(rawurldecode($uriPath), '/');
        if ($relativeUriPath === '') {
            return $node;
        }

        foreach (explode('/', $relativeUriPath) as $pathSegment) {
            $node = $this->findChildDocumentNodeByUriPathSegment($node, $pathSegment);
            if ($node === null) {
                return str_contains($relativeUriPath, '/')
                    ? null
                    : $this->findDescendantDocumentNodeByLabel($siteNode, $relativeUriPath);
            }
        }

        return $node;
    }

    private function findChildDocumentNodeByUriPathSegment($node, string $pathSegment)
    {
        foreach ($this->findChildDocumentNodes($node) as $childNode) {
            if ((string)$childNode->getProperty('uriPathSegment') === $pathSegment) {
                return $childNode;
            }
        }

        return null;
    }

    private function findDescendantDocumentNodeByLabel($node, string $label)
    {
        $normalizedLabel = $this->normalizeLabel($label);
        if ($normalizedLabel === '') {
            return null;
        }

        foreach ($this->findChildDocumentNodes($node) as $childNode) {
            if ($this->documentNodeMatchesLabel($childNode, $normalizedLabel)) {
                return $childNode;
            }

            $matchingDescendant = $this->findDescendantDocumentNodeByLabel($childNode, $label);
            if ($matchingDescendant !== null) {
                return $matchingDescendant;
            }
        }

        return null;
    }

    private function findChildDocumentNodes($node): iterable
    {
        $childNodes = method_exists($node, 'getChildNodes')
            ? $node->getChildNodes('Neos.Neos:Document')
            : $node->findChildNodes();

        foreach ($childNodes as $childNode) {
            if (!$childNode->getNodeType()->isOfType('Neos.Neos:Document')) {
                continue;
            }

            yield $childNode;
        }
    }

    private function documentNodeMatchesLabel($node, string $normalizedLabel): bool
    {
        foreach (['title', 'titleOverride', 'heroTitle'] as $propertyName) {
            if ($this->normalizeLabel((string)$node->getProperty($propertyName)) === $normalizedLabel) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLabel(string $label): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? ''), 'UTF-8');
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
