<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlProfile as AbstractCrawlProfile;

class CrawlNonExcludedUrls extends AbstractCrawlProfile
{
    /**
     * @var array
     * @Flow\InjectConfiguration(path="excludeUrls")
     */
    protected $excludeUrlRegexPatterns = [];

    public function shouldCrawl(UriInterface $url): bool
    {
        foreach ($this->excludeUrlRegexPatterns as $excludeUrlRegexPattern) {
            $match = preg_match($excludeUrlRegexPattern, (string)$url);
            if ($match === 0) {
                continue;
            } elseif ($match === 1) {
                return false;
            } elseif ($match === false) {
                throw new \RuntimeException('Invalid regex pattern: ' . $excludeUrlRegexPattern, 1668185080);
            }
        }
        return true;
    }
}
