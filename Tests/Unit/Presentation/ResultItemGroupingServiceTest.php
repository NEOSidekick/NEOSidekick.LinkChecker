<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Presentation;

use DateTimeImmutable;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Presentation\ResultItemGroupingService;
use NEOSidekick\LinkChecker\Presentation\ResultItemView;
use Neos\Flow\Tests\UnitTestCase;

class ResultItemGroupingServiceTest extends UnitTestCase
{
    private ResultItemGroupingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ResultItemGroupingService();
    }

    /** @test */
    public function targetGroupingCollapsesDuplicatesAndCountsAffectedSources(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'node://missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/a', 'node://missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/b', 'node://missing', 404),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);
        $group = $list->getGroups()[0];

        self::assertSame(1, $list->getGroupCount());
        self::assertSame(2, $group->getAffectedSourceCount());
        self::assertSame(3, $group->getOccurrenceCount());
        self::assertSame(2, \count($group->getChildren()));
        self::assertSame(1, $group->getDuplicateCount());
    }

    /** @test */
    public function targetGroupingKeepsSameTargetSeparatePerDomain(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'https://example.com/missing', 404),
            $this->createLink('lab.neos.eu', '/sites/lab/a', 'https://example.com/missing', 404),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);

        self::assertSame(2, $list->getGroupCount());
        self::assertSame(2, $list->getRawCount());
    }

    /** @test */
    public function facetOptionsCountTargetIssuesNotSourceOccurrences(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'https://example.com/missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/b', 'https://example.com/missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/c', 'node://missing', 404),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);

        self::assertSame(2, $list->getGroupCount());
        self::assertSame(3, $list->getRawCount());
        self::assertSame(2, $this->countForOption($list->getTargetTypeOptions(), 'all'));
        self::assertSame(1, $this->countForOption($list->getTargetTypeOptions(), 'externalUrl'));
        self::assertSame(1, $this->countForOption($list->getTargetTypeOptions(), 'internalNode'));
        self::assertSame(2, $this->countForOption($list->getDomainOptions(), 'all'));
        self::assertSame(2, $this->countForOption($list->getDomainOptions(), 'www.neos.eu'));
        self::assertSame(2, $this->countForOption($list->getStatusOptions(), 'all'));
        self::assertSame(2, $this->countForOption($list->getStatusOptions(), '404'));
    }

    /** @test */
    public function sameTargetWithMixedStatusesCreatesSeparateGroups(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'https://example.com/missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/b', 'https://example.com/missing', 410),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);
        $statusCodes = array_map(static fn ($group) => $group->getStatusCode(), $list->getGroups());
        sort($statusCodes);

        self::assertSame(2, $list->getGroupCount());
        self::assertSame([404, 410], $statusCodes);
    }

    /** @test */
    public function sourceGroupingShowsAllBrokenTargetsForOnePage(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'node://missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/a', 'https://example.com/gone', 410),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_SOURCE, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);
        $group = $list->getGroups()[0];

        self::assertSame(1, $list->getGroupCount());
        self::assertSame('/sites/www/a', $group->getLabel());
        self::assertSame(2, \count($group->getChildren()));
    }

    /** @test */
    public function targetTypeStatusDomainAndImpactFiltersAreApplied(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'node://missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/b', 'node://missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/c', 'node://missing', 404),
            $this->createLink('lab.neos.eu', '/sites/lab/a', 'https://example.com/missing', 404),
            $this->createLink('lab.neos.eu', '/sites/lab/b', 'tel:123', 490),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'internalNode', 'www.neos.eu', '404', ResultItemGroupingService::IMPACT_3_PLUS);

        self::assertSame(1, $list->getGroupCount());
        self::assertSame('internalNode', $list->getGroups()[0]->getTargetType());
        self::assertSame(3, $list->getGroups()[0]->getAffectedSourceCount());
    }

    /** @test */
    public function targetTypesAreDetectedForFacetOptions(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'node://missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/b', 'https://example.com/missing', 404),
            $this->createLink('www.neos.eu', '/sites/www/c', 'tel:123', 490),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);
        $countsByIdentifier = [];
        foreach ($list->getTargetTypeOptions() as $option) {
            $countsByIdentifier[$option->getIdentifier()] = $option->getCount();
        }

        self::assertSame(1, $countsByIdentifier['internalNode']);
        self::assertSame(1, $countsByIdentifier['externalUrl']);
        self::assertSame(1, $countsByIdentifier['otherProtocol']);
    }

    /** @test */
    public function statusOptionsKeepNumericLabelForTranslationFallback(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'https://example.com/unexpected', 599),
        ];

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);

        self::assertSame('599', $this->labelForOption($list->getStatusOptions(), '599'));
    }

    /** @test */
    public function impactOptionsUseMutuallyExclusiveAffectedSourceBuckets(): void
    {
        $links = [
            $this->createLink('www.neos.eu', '/sites/www/a', 'node://low', 404),
            $this->createLink('www.neos.eu', '/sites/www/b', 'node://low', 404),
        ];

        foreach (range(1, 3) as $index) {
            $links[] = $this->createLink('www.neos.eu', '/sites/www/medium-' . $index, 'node://medium', 404);
        }

        foreach (range(1, 10) as $index) {
            $links[] = $this->createLink('www.neos.eu', '/sites/www/high-' . $index, 'node://high', 404);
        }

        $list = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_ALL);
        $countsByIdentifier = [];
        foreach ($list->getImpactOptions() as $option) {
            $countsByIdentifier[$option->getIdentifier()] = $option->getCount();
        }

        self::assertSame(3, $countsByIdentifier[ResultItemGroupingService::IMPACT_ALL]);
        self::assertSame(1, $countsByIdentifier[ResultItemGroupingService::IMPACT_10_PLUS]);
        self::assertSame(1, $countsByIdentifier[ResultItemGroupingService::IMPACT_3_PLUS]);
        self::assertSame(1, $countsByIdentifier[ResultItemGroupingService::IMPACT_LOW]);

        $mediumList = $this->service->group($links, ResultItemGroupingService::MODE_TARGET, 'all', 'all', 'all', ResultItemGroupingService::IMPACT_3_PLUS);
        self::assertSame(1, $mediumList->getGroupCount());
        self::assertSame(3, $mediumList->getGroups()[0]->getAffectedSourceCount());
    }

    private function createLink(string $domain, string $sourcePath, string $target, int $statusCode): ResultItemView
    {
        $resultItem = new ResultItem();
        $resultItem->setDomain($domain);
        $resultItem->setSource(null);
        $resultItem->setSourcePath($sourcePath);
        $resultItem->setTarget($target);
        $resultItem->setStatusCode($statusCode);
        $resultItem->setCreatedAt(new DateTimeImmutable('2026-06-18 12:00:00'));
        $resultItem->setCheckedAt(new DateTimeImmutable('2026-06-18 13:00:00'));

        return new ResultItemView(
            $resultItem,
            $sourcePath,
            'https://' . $domain . str_replace('/sites/www', '', $sourcePath),
            '/neos/edit?node=' . rawurlencode($sourcePath),
            $target,
            str_starts_with($target, 'http') ? $target : null
        );
    }

    private function countForOption(array $options, string $identifier): int
    {
        foreach ($options as $option) {
            if ($option->getIdentifier() === $identifier) {
                return $option->getCount();
            }
        }

        self::fail(sprintf('Missing option "%s"', $identifier));
    }

    private function labelForOption(array $options, string $identifier): string
    {
        foreach ($options as $option) {
            if ($option->getIdentifier() === $identifier) {
                return $option->getLabel();
            }
        }

        self::fail(sprintf('Missing option "%s"', $identifier));
    }
}
