<?php

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Spatie\Crawler\CrawlProfiles\CrawlProfile;

/**
 * @Flow\Scope("singleton")
 */
class WebCrawlerFactory
{
    /**
     * @Flow\InjectConfiguration(path="clientOptions")
     * @var array
     */
    protected $settings;

    public function createCrawler(CrawlProfile $crawlProfile, CrawlObserver $crawlObserver): Crawler
    {
        // If no this->settings are configured we just set timeout and allow_redirect.
        $clientOptions = [
            RequestOptions::TIMEOUT => 100,
            RequestOptions::ALLOW_REDIRECTS => $this->buildAllowRedirectsOption(),
        ];

        if (isset($this->settings['cookies']) && is_bool($this->settings['cookies'])) {
            $clientOptions[RequestOptions::COOKIES] = $this->settings['cookies'];
        }

        if (isset($this->settings['connectionTimeout']) && is_numeric($this->settings['connectionTimeout'])) {
            $clientOptions[RequestOptions::CONNECT_TIMEOUT] = (int)$this->settings['connectionTimeout'];
        }

        if (isset($this->settings['timeout']) && is_numeric($this->settings['timeout'])) {
            $clientOptions[RequestOptions::TIMEOUT] = (int)$this->settings['timeout'];
        }

        if (
            isset($this->settings['auth']) && is_array($this->settings['auth'])
            && count($this->settings['auth']) > 1
        ) {
            $clientOptions[RequestOptions::AUTH] = $this->settings['auth'];
        }

        $userAgent = $this->resolveUserAgent();
        if ($userAgent !== '') {
            // Some hosts block the default Guzzle user agent; an honest descriptive UA reduces false positives.
            $clientOptions[RequestOptions::HEADERS]['User-Agent'] = $userAgent;
        }

        $handler = HandlerStack::create();

        if (isset($this->settings['retryAttempts']) && is_numeric($this->settings['retryAttempts']) && $this->settings['retryAttempts'] >= 0) {
            $handler->push(
                self::createRetryTransientFailuresMiddleware((int)$this->settings['retryAttempts'])
            );
        }

        $clientOptions["handler"] = $handler;

        $crawler = new Crawler(new Client($clientOptions));

        if ($userAgent !== '') {
            $crawler->setUserAgent($userAgent);
        }

        $crawler->setCrawlObserver($crawlObserver);
        $crawler->setCrawlProfile($crawlProfile);

        $concurrency = 10;
        if (isset($this->settings['concurrency']) && (int)$this->settings['concurrency'] >= 0) {
            $concurrency = (int)$this->settings['concurrency'];
        }
        $crawler->setConcurrency($concurrency);

        if (!isset($this->settings['ignoreRobots']) || $this->settings['ignoreRobots']) {
            $crawler->ignoreRobots();
        }

        return $crawler;
    }

    /**
     * Build the Guzzle allow_redirects option. Following redirects is essential to avoid reporting
     * every 301/302 to a working page as broken. Set allowRedirects to false to opt out entirely.
     *
     * @return array|bool
     */
    private function buildAllowRedirectsOption()
    {
        if (isset($this->settings['allowRedirects']) && $this->settings['allowRedirects'] === false) {
            return false;
        }

        $maxRedirects = 5;
        if (isset($this->settings['maxRedirects']) && is_numeric($this->settings['maxRedirects'])) {
            $maxRedirects = (int)$this->settings['maxRedirects'];
        }

        return [
            'max' => $maxRedirects,
            'strict' => false,
            'referer' => false,
            'protocols' => ['http', 'https'],
            'track_redirects' => false,
        ];
    }

    private function resolveUserAgent(): string
    {
        if (isset($this->settings['userAgent']) && is_string($this->settings['userAgent'])) {
            return trim($this->settings['userAgent']);
        }

        return '';
    }

    /**
     * Retry only genuinely transient failures (connection problems, rate limiting and gateway
     * errors) so that a temporary blip is not persisted as a broken link. Permanent failures such
     * as 404 are never retried.
     */
    private static function createRetryTransientFailuresMiddleware(int $retryAttempts)
    {
        $transientStatusCodes = [429, 502, 503, 504];

        return Middleware::retry(
            function (
                $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?\Throwable $exception = null
            ) use ($retryAttempts, $transientStatusCodes) {
                if ($retries >= $retryAttempts) {
                    return false;
                }
                // Timeouts, connection resets and transient DNS failures.
                if ($exception instanceof ConnectException) {
                    return true;
                }
                if ($response instanceof ResponseInterface && in_array($response->getStatusCode(), $transientStatusCodes, true)) {
                    return true;
                }
                return false;
            },
            function (
                $numberOfRetries,
                ?ResponseInterface $response = null
            ) {
                // Honor an explicit Retry-After header when the server provides one.
                $retryAfterMs = self::retryAfterDelayInMilliseconds($response);
                if ($retryAfterMs !== null) {
                    return $retryAfterMs;
                }

                // Exponential backoff with jitter, capped to keep total runtime bounded.
                $baseDelay = 1000 * (2 ** ($numberOfRetries - 1));
                $cappedDelay = min($baseDelay, 30000);

                return $cappedDelay + random_int(0, 250);
            }
        );
    }

    private static function retryAfterDelayInMilliseconds(?ResponseInterface $response): ?int
    {
        if (!$response instanceof ResponseInterface || !$response->hasHeader('Retry-After')) {
            return null;
        }

        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if ($retryAfter === '') {
            return null;
        }

        if (is_numeric($retryAfter)) {
            return min((int)$retryAfter, 60) * 1000;
        }

        $timestamp = strtotime($retryAfter);
        if ($timestamp === false) {
            return null;
        }

        $deltaSeconds = $timestamp - time();
        if ($deltaSeconds <= 0) {
            return 0;
        }

        return min($deltaSeconds, 60) * 1000;
    }
}
