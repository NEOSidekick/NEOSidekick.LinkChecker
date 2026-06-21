<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use Neos\Cache\Frontend\VariableFrontend;
use NEOSidekick\LinkChecker\Infrastructure\LinkTargetCache;
use Neos\Flow\Tests\UnitTestCase;

class LinkTargetCacheTest extends UnitTestCase
{
    private function createCache(bool $enabled, array &$store): LinkTargetCache
    {
        $frontend = $this->getMockBuilder(VariableFrontend::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['has', 'set', 'get'])
            ->getMock();

        $frontend->method('has')->willReturnCallback(static function (string $id) use (&$store): bool {
            return array_key_exists($id, $store);
        });
        $frontend->method('set')->willReturnCallback(static function (string $id, $value) use (&$store): void {
            $store[$id] = $value;
        });

        $linkTargetCache = new LinkTargetCache();
        $this->inject($linkTargetCache, 'cache', $frontend);
        $this->inject($linkTargetCache, 'enabled', $enabled);
        $this->inject($linkTargetCache, 'okLifetime', 604800);

        return $linkTargetCache;
    }

    /** @test */
    public function rememberedUrlIsConsideredFresh(): void
    {
        $store = [];
        $cache = $this->createCache(true, $store);

        self::assertFalse($cache->isFresh('https://example.com/page'));
        $cache->remember('https://example.com/page');
        self::assertTrue($cache->isFresh('https://example.com/page'));
    }

    /** @test */
    public function equivalentUrlsShareTheSameCacheEntry(): void
    {
        $store = [];
        $cache = $this->createCache(true, $store);

        // Differing scheme/host casing and default port must map to the same entry.
        $cache->remember('HTTPS://Example.com:443/page');
        self::assertTrue($cache->isFresh('https://example.com/page'));
    }

    /** @test */
    public function disabledCacheNeverStoresOrReportsFreshness(): void
    {
        $store = [];
        $cache = $this->createCache(false, $store);

        $cache->remember('https://example.com/page');
        self::assertSame([], $store);
        self::assertFalse($cache->isFresh('https://example.com/page'));
    }
}
