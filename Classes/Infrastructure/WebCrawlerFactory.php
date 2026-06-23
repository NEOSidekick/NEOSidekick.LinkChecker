<?php

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
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

    /**
     * @Flow\InjectConfiguration(path="performance")
     * @var array
     */
    protected $performance = [];

    /**
     * @var LinkTargetCache
     * @Flow\Inject
     */
    protected $linkTargetCache;

    public function createCrawler(CrawlProfile $crawlProfile, CrawlObserver $crawlObserver, ?string $baseHost = null): Crawler
    {
        $userAgent = $this->resolveUserAgent();
        $crawler = new Crawler($this->createClient($baseHost));

        if ($userAgent !== '') {
            $crawler->setUserAgent($userAgent);
        }

        $crawler->setCrawlObserver($crawlObserver);
        $crawler->setCrawlProfile($crawlProfile);

        // Cap the number of body bytes read per page to bound memory on large documents.
        $maximumResponseSize = (int)($this->performance['maximumResponseSize'] ?? 0);
        if ($maximumResponseSize > 0) {
            $crawler->setMaximumResponseSize($maximumResponseSize);
        }

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

    private function createClient(?string $baseHost = null): Client
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

        // The following middlewares only make sense relative to the site being crawled, so they are
        // skipped when no base host is known (e.g. when the client is reused for other purposes).
        $baseHost = $baseHost !== null ? strtolower(trim($baseHost)) : '';
        if ($baseHost !== '') {
            $requestsPerSecond = (float)($this->performance['perHostRequestsPerSecond'] ?? 0);
            if ($requestsPerSecond > 0) {
                $handler->push($this->createPerHostRateLimitMiddleware($baseHost, $requestsPerSecond));
            }

            if (($this->performance['headFirst'] ?? false) === true) {
                $rangeBytes = (int)($this->performance['externalRangeBytes'] ?? 0);
                $handler->push($this->createHeadFirstMiddleware($baseHost, $rangeBytes));
            }

            if ($this->linkTargetCache->isEnabled()) {
                $handler->push($this->createResultCacheMiddleware($baseHost));
            }
        }

        $clientOptions["handler"] = $handler;

        return new Client($clientOptions);
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
            ) use (
                $retryAttempts,
                $transientStatusCodes
            ) {
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

    /**
     * For external links we only need the status code, so issue a cheap HEAD request first. Many
     * servers wrongly reject HEAD with 403/405/416/501; in that case we fall back to GET. The byte
     * range cap is only applied to GET requests because several servers reject ranged HEAD probes.
     * Internal pages keep using GET because their body is required for link discovery.
     */
    private function createHeadFirstMiddleware(string $baseHost, int $externalRangeBytes): callable
    {
        return static function (callable $handler) use ($baseHost, $externalRangeBytes): callable {
            return static function (
                RequestInterface $request,
                array $options
            ) use (
                $handler,
                $baseHost,
                $externalRangeBytes
            ) {
                if (strtolower($request->getUri()->getHost()) === $baseHost) {
                    return $handler($request, $options);
                }

                if ($request->getMethod() !== 'GET') {
                    return $handler($request, $options);
                }

                $requestWithoutRange = $request->withoutHeader('Range');
                $getRequest = $requestWithoutRange;
                if ($externalRangeBytes > 0) {
                    $getRequest = $getRequest->withHeader('Range', 'bytes=0-' . $externalRangeBytes);
                }

                $retryWithoutRange = static fn () => $handler($requestWithoutRange, $options);
                $retryWithGet = static function () use (
                    $handler,
                    $getRequest,
                    $options,
                    $retryWithoutRange,
                    $externalRangeBytes
                ) {
                    return $handler($getRequest, $options)->then(
                        static function (ResponseInterface $response) use ($retryWithoutRange, $externalRangeBytes) {
                            if ($externalRangeBytes > 0 && $response->getStatusCode() === 416) {
                                return $retryWithoutRange();
                            }

                            return $response;
                        },
                        static function ($reason) use ($retryWithoutRange, $externalRangeBytes) {
                            if ($externalRangeBytes > 0 && $reason instanceof RequestException) {
                                $response = $reason->getResponse();
                                if ($response instanceof ResponseInterface && $response->getStatusCode() === 416) {
                                    return $retryWithoutRange();
                                }
                            }

                            return Create::rejectionFor($reason);
                        }
                    );
                };

                return $handler($requestWithoutRange->withMethod('HEAD'), $options)->then(
                    static function (ResponseInterface $response) use ($retryWithGet) {
                        if (in_array($response->getStatusCode(), [403, 405, 416, 501], true)) {
                            return $retryWithGet();
                        }
                        return $response;
                    },
                    static function ($reason) use ($retryWithGet) {
                        if ($reason instanceof RequestException) {
                            $response = $reason->getResponse();
                            if (
                                $response instanceof ResponseInterface
                                && in_array($response->getStatusCode(), [403, 405, 416, 501], true)
                            ) {
                                return $retryWithGet();
                            }
                        }
                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /**
     * Throttle requests to external hosts to at most N per second so a host that appears on many
     * pages is not hammered. The site's own host is governed by the global concurrency instead.
     * Uses Guzzle's non-blocking delay option so the concurrent request pool keeps flowing.
     */
    private function createPerHostRateLimitMiddleware(string $baseHost, float $requestsPerSecond): callable
    {
        $minIntervalMs = 1000.0 / $requestsPerSecond;
        $hostNextAvailableMs = [];

        return static function (callable $handler) use ($baseHost, $minIntervalMs, &$hostNextAvailableMs): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $baseHost, $minIntervalMs, &$hostNextAvailableMs) {
                $host = strtolower($request->getUri()->getHost());
                if ($host === '' || $host === $baseHost) {
                    return $handler($request, $options);
                }

                $nowMs = microtime(true) * 1000;
                $earliestMs = max($nowMs, $hostNextAvailableMs[$host] ?? 0.0);
                $hostNextAvailableMs[$host] = $earliestMs + $minIntervalMs;

                $delayMs = $earliestMs - $nowMs;
                if ($delayMs > 0) {
                    $options[RequestOptions::DELAY] = ($options[RequestOptions::DELAY] ?? 0) + $delayMs;
                }

                return $handler($request, $options);
            };
        };
    }

    /**
     * Skip external links that were confirmed healthy on a recent run, and record freshly confirmed
     * healthy external links for next time. Internal pages are always fetched so they stay current.
     */
    private function createResultCacheMiddleware(string $baseHost): callable
    {
        $cache = $this->linkTargetCache;

        return static function (callable $handler) use ($cache, $baseHost): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $cache, $baseHost) {
                $url = (string)$request->getUri();
                $host = strtolower($request->getUri()->getHost());
                $isExternal = $host !== '' && $host !== $baseHost;

                if ($isExternal && $cache->isFresh($url)) {
                    return Create::promiseFor(new Response(200));
                }

                return $handler($request, $options)->then(
                    static function (ResponseInterface $response) use ($cache, $isExternal, $url) {
                        $statusCode = $response->getStatusCode();
                        if ($isExternal && $statusCode >= 200 && $statusCode < 300) {
                            $cache->remember($url);
                        }
                        return $response;
                    }
                );
            };
        };
    }
}
