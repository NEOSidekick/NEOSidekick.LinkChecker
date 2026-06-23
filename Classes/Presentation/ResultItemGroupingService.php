<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class ResultItemGroupingService
{
    public const MODE_TARGET = 'target';
    public const MODE_SOURCE = 'source';
    public const MODE_DOMAIN = 'domain';
    public const MODE_STATUS = 'status';
    public const IMPACT_ALL = 'all';
    public const IMPACT_10_PLUS = '10Plus';
    public const IMPACT_3_PLUS = '3Plus';
    public const IMPACT_LOW = 'low';

    /**
     * @param array<ResultItemView> $links
     */
    public function group(
        array $links,
        string $mode,
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact,
        bool $filterByImpact = true
    ): GroupedResultItemListView
    {
        $mode = $this->normalizeMode($mode);
        $targetType = $targetType !== '' ? $targetType : 'all';
        $domain = $domain !== '' ? $domain : 'all';
        $statusCode = $statusCode !== '' ? $statusCode : 'all';
        $impact = $this->normalizeImpact($impact);

        $filteredLinks = array_values(array_filter(
            $links,
            fn (ResultItemView $link) => $this->matchesFilters($link, $targetType, $domain, $statusCode)
        ));

        $groups = $this->createGroups($filteredLinks, $mode);
        $impactOptions = $this->createImpactOptions($groups);

        if ($filterByImpact && $impact !== self::IMPACT_ALL) {
            $groups = array_values(array_filter(
                $groups,
                fn (ResultItemGroupView $group) => $this->groupMatchesImpact($group, $impact)
            ));
        }

        usort($groups, fn (ResultItemGroupView $left, ResultItemGroupView $right) => $this->compareGroups($left, $right));

        return new GroupedResultItemListView(
            $groups,
            $this->createModeOptions(),
            $this->createTargetTypeOptions($links),
            $this->createDomainOptions($links),
            $this->createStatusOptions($links),
            $impactOptions,
            $mode,
            $targetType,
            $domain,
            $statusCode,
            $impact,
            \count($links)
        );
    }

    private function normalizeMode(string $mode): string
    {
        if (\in_array($mode, [self::MODE_TARGET, self::MODE_SOURCE, self::MODE_DOMAIN, self::MODE_STATUS], true)) {
            return $mode;
        }

        return self::MODE_TARGET;
    }

    private function normalizeImpact(string $impact): string
    {
        if (\in_array($impact, [self::IMPACT_ALL, self::IMPACT_10_PLUS, self::IMPACT_3_PLUS, self::IMPACT_LOW], true)) {
            return $impact;
        }

        return self::IMPACT_ALL;
    }

    private function matchesFilters(ResultItemView $link, string $targetType, string $domain, string $statusCode): bool
    {
        if ($targetType !== 'all' && $this->targetType($link) !== $targetType) {
            return false;
        }

        if ($domain !== 'all' && $link->getDomain() !== $domain) {
            return false;
        }

        if ($statusCode !== 'all' && (string)$link->getStatusCode() !== $statusCode) {
            return false;
        }

        return true;
    }

    /**
     * @param array<ResultItemView> $links
     * @return array<ResultItemGroupView>
     */
    private function createGroups(array $links, string $mode): array
    {
        $groups = [];

        foreach ($links as $link) {
            $groupKey = $this->groupKey($link, $mode);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $this->createGroupSeed($link, $groupKey, $mode);
            }

            $childKey = $this->childKey($link);
            if (!isset($groups[$groupKey]['children'][$childKey])) {
                $groups[$groupKey]['children'][$childKey] = [
                    'link' => $link,
                    'count' => 0,
                ];
            }

            $groups[$groupKey]['children'][$childKey]['count']++;
            $groups[$groupKey]['occurrenceCount']++;
            $groups[$groupKey]['sourceKeys'][$this->sourceKey($link)] = true;
            $groups[$groupKey]['domains'][$link->getDomain()] = $link->getDomain();
            $groups[$groupKey]['targetTypes'][$this->targetType($link)] = true;

            if ($link->getCheckedAt() > $groups[$groupKey]['lastCheckedAt']) {
                $groups[$groupKey]['lastCheckedAt'] = $link->getCheckedAt();
            }
        }

        return array_values(array_map(fn (array $group) => $this->createGroupView($group), $groups));
    }

    private function createGroupSeed(ResultItemView $link, string $groupKey, string $mode): array
    {
        return [
            'key' => $groupKey,
            'label' => $this->groupLabel($link, $mode),
            'uri' => $this->groupUri($link, $mode),
            'secondaryLabel' => $this->groupSecondaryLabel($link, $mode),
            'statusCode' => $this->groupStatusCode($link, $mode),
            'children' => [],
            'sourceKeys' => [],
            'domains' => [],
            'targetTypes' => [],
            'occurrenceCount' => 0,
            'lastCheckedAt' => $link->getCheckedAt(),
        ];
    }

    private function createGroupView(array $group): ResultItemGroupView
    {
        $children = array_map(
            fn (array $child) => new ResultItemGroupChildView($child['link'], $child['count']),
            array_values($group['children'])
        );

        usort(
            $children,
            fn (ResultItemGroupChildView $left, ResultItemGroupChildView $right) => strcasecmp(
                $left->getLink()->getSourceLabel() . $left->getLink()->getTargetLabel(),
                $right->getLink()->getSourceLabel() . $right->getLink()->getTargetLabel()
            )
        );

        return new ResultItemGroupView(
            $group['key'],
            $group['label'],
            $group['uri'],
            $group['secondaryLabel'],
            $group['statusCode'],
            \count($group['targetTypes']) === 1 ? (string)array_key_first($group['targetTypes']) : 'mixed',
            $children,
            \count($group['sourceKeys']),
            $group['occurrenceCount'],
            $group['domains'],
            $group['lastCheckedAt']
        );
    }

    private function groupKey(ResultItemView $link, string $mode): string
    {
        return match ($mode) {
            self::MODE_SOURCE => 'source|' . $this->sourceKey($link),
            self::MODE_DOMAIN => 'domain|' . $link->getDomain(),
            self::MODE_STATUS => 'status|' . $link->getStatusCode(),
            default => 'target|' . $this->issueKey($link),
        };
    }

    private function childKey(ResultItemView $link): string
    {
        return implode('|', [
            $link->getDomain(),
            $link->getResultItem()->getSource() ?? '',
            $link->getResultItem()->getSourcePath() ?? '',
            $link->getTargetFallbackLabel(),
            (string)$link->getStatusCode(),
        ]);
    }

    private function sourceKey(ResultItemView $link): string
    {
        return implode('|', [
            $link->getDomain(),
            $link->getResultItem()->getSource() ?? '',
            $link->getResultItem()->getSourcePath() ?? $link->getSourceFrontendUri(),
        ]);
    }

    private function groupLabel(ResultItemView $link, string $mode): string
    {
        return match ($mode) {
            self::MODE_SOURCE => $link->getSourceLabel(),
            self::MODE_DOMAIN => $link->getDomain(),
            self::MODE_STATUS => (string)$link->getStatusCode(),
            default => $link->getTargetLabel(),
        };
    }

    private function groupUri(ResultItemView $link, string $mode): ?string
    {
        return match ($mode) {
            self::MODE_SOURCE => $link->getSourceFrontendUri(),
            self::MODE_DOMAIN, self::MODE_STATUS => null,
            default => $link->getTargetUri(),
        };
    }

    private function groupSecondaryLabel(ResultItemView $link, string $mode): ?string
    {
        return match ($mode) {
            self::MODE_SOURCE => $link->getResultItem()->getSourcePath(),
            self::MODE_TARGET => $link->getTargetFallbackLabel() !== $link->getTargetLabel() ? $link->getTargetFallbackLabel() : null,
            default => null,
        };
    }

    private function groupStatusCode(ResultItemView $link, string $mode): ?int
    {
        return $mode === self::MODE_DOMAIN ? null : $link->getStatusCode();
    }

    private function targetType(ResultItemView $link): string
    {
        if ($link->isInternalTarget()) {
            return 'internalNode';
        }

        $target = strtolower($link->getTargetFallbackLabel());

        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return 'externalUrl';
        }

        return 'otherProtocol';
    }

    private function issueKey(ResultItemView $link): string
    {
        return ResultItem::createIssueFingerprint(
            $link->getDomain(),
            $link->getTarget(),
            $link->getStatusCode()
        );
    }

    private function compareGroups(ResultItemGroupView $left, ResultItemGroupView $right): int
    {
        return $right->getAffectedSourceCount() <=> $left->getAffectedSourceCount()
            ?: $this->statusSeverity($right->getStatusCode()) <=> $this->statusSeverity($left->getStatusCode())
            ?: $right->getLastCheckedAt() <=> $left->getLastCheckedAt()
            ?: strcasecmp($left->getLabel(), $right->getLabel());
    }

    private function groupMatchesImpact(ResultItemGroupView $group, string $impact): bool
    {
        $affectedSourceCount = $group->getAffectedSourceCount();

        return match ($impact) {
            self::IMPACT_10_PLUS => $affectedSourceCount >= 10,
            self::IMPACT_3_PLUS => $affectedSourceCount >= 3 && $affectedSourceCount < 10,
            self::IMPACT_LOW => $affectedSourceCount < 3,
            default => true,
        };
    }

    private function statusSeverity(?int $statusCode): int
    {
        if ($statusCode === null) {
            return 0;
        }

        if ($statusCode === 0 || $statusCode >= 500) {
            return 5;
        }

        if (\in_array($statusCode, [404, 410], true)) {
            return 4;
        }

        if ($statusCode === 403) {
            return 3;
        }

        if ($statusCode >= 400) {
            return 2;
        }

        if ($statusCode >= 300) {
            return 1;
        }

        return 0;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    private function createModeOptions(): array
    {
        return [
            new ResultItemFilterOptionView(self::MODE_TARGET, 'By broken target', 'group.mode.target'),
            new ResultItemFilterOptionView(self::MODE_SOURCE, 'By source page', 'group.mode.source'),
            new ResultItemFilterOptionView(self::MODE_DOMAIN, 'By site/domain', 'group.mode.domain'),
            new ResultItemFilterOptionView(self::MODE_STATUS, 'By error type', 'group.mode.status'),
        ];
    }

    /**
     * @param array<ResultItemView> $links
     * @return array<ResultItemFilterOptionView>
     */
    private function createTargetTypeOptions(array $links): array
    {
        $counts = $this->createIssueCountsBy($links, fn (ResultItemView $link) => $this->targetType($link));

        return [
            new ResultItemFilterOptionView('all', 'All target types', 'filter.allTargetTypes', $counts['all']),
            new ResultItemFilterOptionView('internalNode', 'Internal node', 'targetType.internalNode', $counts['internalNode'] ?? 0),
            new ResultItemFilterOptionView('externalUrl', 'External URL', 'targetType.externalUrl', $counts['externalUrl'] ?? 0),
            new ResultItemFilterOptionView('otherProtocol', 'Other protocol', 'targetType.otherProtocol', $counts['otherProtocol'] ?? 0),
        ];
    }

    /**
     * @param array<ResultItemView> $links
     * @return array<ResultItemFilterOptionView>
     */
    private function createDomainOptions(array $links): array
    {
        $counts = $this->createIssueCountsBy($links, fn (ResultItemView $link) => $link->getDomain());
        ksort($counts);

        $options = [new ResultItemFilterOptionView('all', 'All domains', 'filter.allDomains', $counts['all'])];
        foreach ($counts as $domain => $count) {
            if ($domain === 'all') {
                continue;
            }
            $options[] = new ResultItemFilterOptionView((string)$domain, (string)$domain, null, $count);
        }

        return $options;
    }

    /**
     * @param array<ResultItemView> $links
     * @return array<ResultItemFilterOptionView>
     */
    private function createStatusOptions(array $links): array
    {
        $counts = $this->createIssueCountsBy($links, fn (ResultItemView $link) => (string)$link->getStatusCode());
        ksort($counts, SORT_NUMERIC);

        $options = [new ResultItemFilterOptionView('all', 'All statuses', 'filter.allStatuses', $counts['all'])];
        foreach ($counts as $statusCode => $count) {
            if ($statusCode === 'all') {
                continue;
            }
            $options[] = new ResultItemFilterOptionView(
                (string)$statusCode,
                (string)$statusCode,
                $this->statusFilterTranslationId((string)$statusCode),
                $count
            );
        }

        return $options;
    }

    /**
     * @param array<ResultItemGroupView> $groups
     * @return array<ResultItemFilterOptionView>
     */
    private function createImpactOptions(array $groups): array
    {
        $counts = [
            self::IMPACT_ALL => \count($groups),
            self::IMPACT_10_PLUS => 0,
            self::IMPACT_3_PLUS => 0,
            self::IMPACT_LOW => 0,
        ];

        foreach ($groups as $group) {
            foreach ([self::IMPACT_10_PLUS, self::IMPACT_3_PLUS, self::IMPACT_LOW] as $impact) {
                if ($this->groupMatchesImpact($group, $impact)) {
                    $counts[$impact]++;
                    break;
                }
            }
        }

        return [
            new ResultItemFilterOptionView(self::IMPACT_ALL, 'All impact', 'filter.impact.all', $counts[self::IMPACT_ALL]),
            new ResultItemFilterOptionView(self::IMPACT_10_PLUS, '10+ pages', 'filter.impact.10Plus', $counts[self::IMPACT_10_PLUS]),
            new ResultItemFilterOptionView(self::IMPACT_3_PLUS, '3+ pages', 'filter.impact.3Plus', $counts[self::IMPACT_3_PLUS]),
            new ResultItemFilterOptionView(self::IMPACT_LOW, '1-2 pages', 'filter.impact.low', $counts[self::IMPACT_LOW]),
        ];
    }

    private function statusFilterTranslationId(string $statusCode): string
    {
        return match ($statusCode) {
            '490' => 'filter.status.490',
            default => 'error.' . $statusCode,
        };
    }

    /**
     * @param array<ResultItemView> $links
     * @return array<string, int>
     */
    private function createIssueCountsBy(array $links, callable $classifier): array
    {
        $issuesByClassifier = ['all' => []];

        foreach ($links as $link) {
            $classifierKey = (string)$classifier($link);
            $issueKey = $this->issueKey($link);

            $issuesByClassifier['all'][$issueKey] = true;
            $issuesByClassifier[$classifierKey][$issueKey] = true;
        }

        $counts = [];
        foreach ($issuesByClassifier as $classifierKey => $issues) {
            $counts[$classifierKey] = \count($issues);
        }

        return $counts + ['all' => 0];
    }
}
