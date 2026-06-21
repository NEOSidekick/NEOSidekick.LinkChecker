<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\Flow\Annotations as Flow;

/**
 * Decides whether a crawl result is healthy, a hard error (broken) or merely unverifiable (warning).
 *
 * The goal is false-positive reduction: auth walls, bot blocks, rate limiting, redirects and known
 * crawler-hostile hosts must never be reported as broken links, otherwise the report loses its value.
 *
 * @Flow\Scope("singleton")
 */
class LinkStatusClassifier
{
    /**
     * Status codes that indicate the link could not be verified rather than being dead.
     *
     * @Flow\InjectConfiguration(path="classification.treatAsWarning")
     * @var array<int>
     */
    protected array $warningStatusCodes = [];

    /**
     * @Flow\InjectConfiguration(path="classification.detectCloudflareChallenge")
     * @var bool
     */
    protected bool $detectCloudflareChallenge = true;

    /**
     * Hosts that routinely block automated checks. Findings for these are downgraded to warnings.
     *
     * @Flow\InjectConfiguration(path="classification.knownBlockerDomains")
     * @var array<string>
     */
    protected array $knownBlockerDomains = [];

    /**
     * Regex rules (full patterns incl. delimiters) that suppress matching findings entirely.
     * Each rule is either a string pattern or an array {pattern: string, statusCodes?: int[]}.
     *
     * @Flow\InjectConfiguration(path="ignoreRules")
     * @var array<mixed>
     */
    protected array $ignoreRules = [];

    /**
     * @param array<string, array<int, string>> $responseHeaders Guzzle/PSR-7 header map (name => values)
     * @return string one of ResultItem::STATE_OK|STATE_WARNING|STATE_BROKEN
     */
    public function classify(string $targetUrl, int $statusCode, array $responseHeaders = []): string
    {
        if ($this->matchesIgnoreRule($targetUrl, $statusCode)) {
            return ResultItem::STATE_OK;
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return ResultItem::STATE_OK;
        }

        if ($this->isKnownBlockerDomain($targetUrl)) {
            return ResultItem::STATE_WARNING;
        }

        if ($this->isCloudflareChallenge($statusCode, $responseHeaders)) {
            return ResultItem::STATE_WARNING;
        }

        if (in_array($statusCode, $this->warningStatusCodes, true)) {
            return ResultItem::STATE_WARNING;
        }

        // Redirects only reach this point if they were not followed (e.g. redirect loop or the
        // configured maximum was exceeded). That is suspicious but not provably dead.
        if ($statusCode >= 300 && $statusCode < 400) {
            return ResultItem::STATE_WARNING;
        }

        return ResultItem::STATE_BROKEN;
    }

    private function matchesIgnoreRule(string $targetUrl, int $statusCode): bool
    {
        foreach ($this->ignoreRules as $rule) {
            $pattern = is_array($rule) ? ($rule['pattern'] ?? null) : $rule;
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $scopedStatusCodes = is_array($rule) ? ($rule['statusCodes'] ?? null) : null;
            if (is_array($scopedStatusCodes) && $scopedStatusCodes !== [] && !in_array($statusCode, array_map('intval', $scopedStatusCodes), true)) {
                continue;
            }

            $match = @preg_match($pattern, $targetUrl);
            if ($match === false) {
                throw new \RuntimeException('Invalid link checker ignore rule pattern: ' . $pattern, 1718800000);
            }

            if ($match === 1) {
                return true;
            }
        }

        return false;
    }

    private function isKnownBlockerDomain(string $targetUrl): bool
    {
        $host = strtolower((string)parse_url($targetUrl, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($this->knownBlockerDomains as $blockerDomain) {
            $blockerDomain = strtolower(trim((string)$blockerDomain));
            if ($blockerDomain === '') {
                continue;
            }

            if ($host === $blockerDomain || str_ends_with($host, '.' . $blockerDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<int, string>> $responseHeaders
     */
    private function isCloudflareChallenge(int $statusCode, array $responseHeaders): bool
    {
        if (!$this->detectCloudflareChallenge) {
            return false;
        }

        if (!in_array($statusCode, [403, 429, 503], true)) {
            return false;
        }

        $headers = $this->normalizeHeaders($responseHeaders);

        // "cf-mitigated: challenge" is Cloudflare's explicit bot-challenge marker.
        if (isset($headers['cf-mitigated'])) {
            return true;
        }

        // Otherwise require both the Cloudflare server signature and a cf-ray id to avoid false matches.
        $server = $headers['server'] ?? '';
        return str_contains($server, 'cloudflare') && isset($headers['cf-ray']);
    }

    /**
     * @param array<string, array<int, string>> $responseHeaders
     * @return array<string, string>
     */
    private function normalizeHeaders(array $responseHeaders): array
    {
        $normalized = [];
        foreach ($responseHeaders as $name => $values) {
            $normalized[strtolower((string)$name)] = strtolower(implode(' ', (array)$values));
        }

        return $normalized;
    }
}
