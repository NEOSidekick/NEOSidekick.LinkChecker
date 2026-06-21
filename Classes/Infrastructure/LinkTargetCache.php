<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;

/**
 * Remembers external link targets that were confirmed healthy so that recurring crawls can skip
 * re-requesting them until the cached result expires. This is the main lever for both performance
 * and politeness on sites with many stable outbound links.
 *
 * @Flow\Scope("singleton")
 */
class LinkTargetCache
{
    /**
     * @var CacheManager
     * @Flow\Inject
     */
    protected $cacheManager;

    /**
     * @var VariableFrontend
     */
    protected $cache;

    /**
     * @Flow\InjectConfiguration(path="performance.betweenRunCache.enabled")
     * @var bool
     */
    protected bool $enabled = false;

    /**
     * Lifetime in seconds for a healthy result. A shorter value detects newly broken links sooner,
     * a longer value saves more requests.
     *
     * @Flow\InjectConfiguration(path="performance.betweenRunCache.okLifetime")
     * @var int
     */
    protected int $okLifetime = 604800;

    public function initializeObject(): void
    {
        $this->cache = $this->cacheManager->getCache('NEOSidekick_LinkChecker_LinkTarget');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the given target was recently confirmed healthy and may be skipped this run.
     */
    public function isFresh(string $url): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return $this->cache->has($this->entryIdentifier($url));
    }

    /**
     * Record that the given target responded successfully.
     */
    public function remember(string $url): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->cache->set($this->entryIdentifier($url), true, [], max(0, $this->okLifetime));
    }

    private function entryIdentifier(string $url): string
    {
        return sha1($this->normalize($url));
    }

    private function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return strtolower(trim($url));
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $portPart = $port !== null && !$isDefaultPort ? ':' . $port : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $portPart, $path, $query);
    }
}
