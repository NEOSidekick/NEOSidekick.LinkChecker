<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Infrastructure\LinkStatusClassifier;
use Neos\Flow\Tests\UnitTestCase;

class LinkStatusClassifierTest extends UnitTestCase
{
    private function createClassifier(
        array $warningStatusCodes = [401, 403, 429],
        bool $detectCloudflareChallenge = true,
        array $knownBlockerDomains = ['linkedin.com'],
        array $ignoreRules = []
    ): LinkStatusClassifier {
        $classifier = new LinkStatusClassifier();
        $this->inject($classifier, 'warningStatusCodes', $warningStatusCodes);
        $this->inject($classifier, 'detectCloudflareChallenge', $detectCloudflareChallenge);
        $this->inject($classifier, 'knownBlockerDomains', $knownBlockerDomains);
        $this->inject($classifier, 'ignoreRules', $ignoreRules);

        return $classifier;
    }

    /** @test */
    public function successfulResponsesAreOk(): void
    {
        self::assertSame(ResultItem::STATE_OK, $this->createClassifier()->classify('https://example.com', 200));
    }

    /** @test */
    public function notFoundIsBroken(): void
    {
        self::assertSame(ResultItem::STATE_BROKEN, $this->createClassifier()->classify('https://example.com/missing', 404));
        self::assertSame(ResultItem::STATE_BROKEN, $this->createClassifier()->classify('https://example.com/gone', 410));
    }

    /** @test */
    public function serverErrorIsBroken(): void
    {
        self::assertSame(ResultItem::STATE_BROKEN, $this->createClassifier()->classify('https://example.com', 500));
    }

    /** @test */
    public function connectionFailureIsBroken(): void
    {
        self::assertSame(ResultItem::STATE_BROKEN, $this->createClassifier()->classify('https://example.com', 0));
    }

    /** @test */
    public function authBotAndRateLimitCodesAreWarnings(): void
    {
        $classifier = $this->createClassifier();
        self::assertSame(ResultItem::STATE_WARNING, $classifier->classify('https://example.com', 401));
        self::assertSame(ResultItem::STATE_WARNING, $classifier->classify('https://example.com', 403));
        self::assertSame(ResultItem::STATE_WARNING, $classifier->classify('https://example.com', 429));
    }

    /** @test */
    public function unfollowedRedirectIsWarning(): void
    {
        self::assertSame(ResultItem::STATE_WARNING, $this->createClassifier()->classify('https://example.com', 301));
    }

    /** @test */
    public function knownBlockerDomainIsWarningEvenWithUnusualStatus(): void
    {
        // LinkedIn famously answers automated checks with 999.
        $classifier = $this->createClassifier();
        self::assertSame(ResultItem::STATE_WARNING, $classifier->classify('https://www.linkedin.com/in/someone', 999));
        self::assertSame(ResultItem::STATE_WARNING, $classifier->classify('https://linkedin.com/in/someone', 404));
    }

    /** @test */
    public function cloudflareChallengeIsWarning(): void
    {
        $classifier = $this->createClassifier();
        self::assertSame(
            ResultItem::STATE_WARNING,
            $classifier->classify('https://example.com', 403, ['cf-mitigated' => ['challenge']])
        );
        self::assertSame(
            ResultItem::STATE_WARNING,
            $classifier->classify('https://example.com', 503, ['Server' => ['cloudflare'], 'CF-RAY' => ['abc123']])
        );
    }

    /** @test */
    public function cloudflareDetectionCanBeDisabled(): void
    {
        $classifier = $this->createClassifier(detectCloudflareChallenge: false);
        // 403 is still a configured warning code, so use 503 (broken) to prove cloudflare detection is off.
        self::assertSame(
            ResultItem::STATE_BROKEN,
            $classifier->classify('https://example.com', 503, ['cf-mitigated' => ['challenge']])
        );
    }

    /** @test */
    public function ignoreRuleSuppressesMatchingFinding(): void
    {
        $classifier = $this->createClassifier(ignoreRules: ['#^https://intranet\.example\.com/#']);
        self::assertSame(ResultItem::STATE_OK, $classifier->classify('https://intranet.example.com/page', 404));
        self::assertSame(ResultItem::STATE_BROKEN, $classifier->classify('https://example.com/page', 404));
    }

    /** @test */
    public function ignoreRuleCanBeScopedToStatusCodes(): void
    {
        $classifier = $this->createClassifier(ignoreRules: [['pattern' => '#example\.com#', 'statusCodes' => [403]]]);
        self::assertSame(ResultItem::STATE_OK, $classifier->classify('https://example.com/page', 403));
        // 404 is outside the rule scope, so it is still reported.
        self::assertSame(ResultItem::STATE_BROKEN, $classifier->classify('https://example.com/page', 404));
    }
}
