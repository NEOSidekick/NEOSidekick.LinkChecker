<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;

/**
 * Persists the timestamp of the last completed node crawl so that an incremental run can restrict
 * itself to nodes modified since then.
 *
 * @Flow\Scope("singleton")
 */
class IncrementalCrawlTracker
{
    private const LAST_RUN_ENTRY = 'nodeCrawlLastRun';

    /**
     * @var CacheManager
     * @Flow\Inject
     */
    protected $cacheManager;

    /**
     * @var VariableFrontend
     */
    protected $cache;

    public function initializeObject(): void
    {
        $this->cache = $this->cacheManager->getCache('NEOSidekick_LinkChecker_CrawlState');
    }

    public function getLastRun(): ?\DateTimeInterface
    {
        $timestamp = $this->cache->get(self::LAST_RUN_ENTRY);
        if (!is_int($timestamp)) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($timestamp);
    }

    public function setLastRun(\DateTimeInterface $time): void
    {
        $this->cache->set(self::LAST_RUN_ENTRY, $time->getTimestamp());
    }
}
