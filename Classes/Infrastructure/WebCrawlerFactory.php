<?php

namespace NEOSidekick\LinkChecker\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Neos\Flow\Annotations as Flow;
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
            RequestOptions::ALLOW_REDIRECTS => false,
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

        if (isset($this->settings['allowRedirects']) && is_bool($this->settings['allowRedirects'])) {
            $clientOptions[RequestOptions::ALLOW_REDIRECTS] = $this->settings['allowRedirects'];
        }

        if (
            isset($this->settings['auth']) && is_array($this->settings['auth'])
            && count($this->settings['auth']) > 1
        ) {
            $clientOptions[RequestOptions::AUTH] = $this->settings['auth'];
        }

        $handler = HandlerStack::create();

        if (isset($this->settings['retryAttempts']) && is_numeric($this->settings['retryAttempts']) && $this->settings['retryAttempts'] >= 0) {
            $handler->push(
                self::createRetryOnConnectionTimedOutMiddleware((int)$this->settings['retryAttempts'])
            );
        }

        $clientOptions["handler"] = $handler;

        $crawler = new Crawler(new Client($clientOptions));

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

    private static function createRetryOnConnectionTimedOutMiddleware(int $retryAttempts)
    {
        return Middleware::retry(
            function (
                $retries,
                Request $request,
                ?Response $response = null,
                ?\Exception $exception = null
            ) use ($retryAttempts) {
                if ($retries >= $retryAttempts) {
                    return false;
                }
                if ($exception instanceof ConnectException) {
                    return true;
                }
                return false;
            },
            function (
                $numberOfRetries
            ) {
                return 1000 * $numberOfRetries;
            }
        );
    }
}
