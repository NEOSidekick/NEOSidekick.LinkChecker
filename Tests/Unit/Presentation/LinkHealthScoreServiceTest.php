<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Presentation;

use DateTimeImmutable;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Presentation\LinkHealthScoreService;
use NEOSidekick\LinkChecker\Presentation\ResultItemView;
use Neos\Flow\Tests\UnitTestCase;

class LinkHealthScoreServiceTest extends UnitTestCase
{
    private LinkHealthScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LinkHealthScoreService();
    }

    /** @test */
    public function scoreUsesAffectedSourcePagesComparedToTotalPageCount(): void
    {
        $score = $this->service->create([
            $this->createLink('www.neos.eu', '/sites/www/a', ResultItem::STATE_BROKEN),
            $this->createLink('www.neos.eu', '/sites/www/b', ResultItem::STATE_BROKEN),
        ], 50);

        self::assertSame(2, $score['affectedSourcePageCount']);
        self::assertSame(50, $score['totalInternalPageCount']);
        self::assertSame(50, $score['scoreDenominator']);
        self::assertSame(96, $score['score']);
    }

    /** @test */
    public function duplicateFindingsOnSameSourcePageOnlyLowerScoreOnce(): void
    {
        $score = $this->service->create([
            $this->createLink('www.neos.eu', '/sites/www/a', ResultItem::STATE_BROKEN, 'node://missing-a'),
            $this->createLink('www.neos.eu', '/sites/www/a', ResultItem::STATE_BROKEN, 'node://missing-b'),
        ], 20);

        self::assertSame(1, $score['affectedSourcePageCount']);
        self::assertSame(95, $score['score']);
    }

    /** @test */
    public function warningsDoNotLowerScore(): void
    {
        $score = $this->service->create([
            $this->createLink('www.neos.eu', '/sites/www/a', ResultItem::STATE_WARNING),
        ], 10);

        self::assertSame(0, $score['affectedSourcePageCount']);
        self::assertSame(100, $score['score']);
    }

    /** @test */
    public function denominatorFallsBackToAffectedSourceCountWhenPageCountIsStaleOrUnavailable(): void
    {
        $score = $this->service->create([
            $this->createLink('www.neos.eu', '/sites/www/a', ResultItem::STATE_BROKEN),
            $this->createLink('www.neos.eu', '/sites/www/b', ResultItem::STATE_BROKEN),
        ], 0);

        self::assertSame(2, $score['scoreDenominator']);
        self::assertSame(0, $score['score']);
    }

    private function createLink(
        string $domain,
        string $sourcePath,
        string $state,
        string $target = 'https://example.com/missing'
    ): ResultItemView {
        $resultItem = new ResultItem();
        $resultItem->setDomain($domain);
        $resultItem->setSource($sourcePath);
        $resultItem->setSourcePath($sourcePath);
        $resultItem->setTarget($target);
        $resultItem->setStatusCode(404);
        $resultItem->setState($state);
        $resultItem->setCreatedAt(new DateTimeImmutable('2026-06-23T12:00:00+00:00'));
        $resultItem->setCheckedAt(new DateTimeImmutable('2026-06-23T12:00:00+00:00'));

        return new ResultItemView(
            $resultItem,
            $sourcePath,
            'https://' . $domain . $sourcePath,
            null,
            $target,
            $target
        );
    }
}
