<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

final class GroupedResultItemListView
{
    public readonly int $groupCount;
    public readonly bool $hasResults;
    public readonly bool $hasActiveFilters;

    /**
     * @param array<ResultItemGroupView> $groups
     * @param array<ResultItemFilterOptionView> $modeOptions
     * @param array<ResultItemFilterOptionView> $targetTypeOptions
     * @param array<ResultItemFilterOptionView> $domainOptions
     * @param array<ResultItemFilterOptionView> $statusOptions
     * @param array<ResultItemFilterOptionView> $impactOptions
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $modeOptions,
        public readonly array $targetTypeOptions,
        public readonly array $domainOptions,
        public readonly array $statusOptions,
        public readonly array $impactOptions,
        public readonly string $activeMode,
        public readonly string $activeTargetType,
        public readonly string $activeDomain,
        public readonly string $activeStatusCode,
        public readonly string $activeImpact,
        public readonly int $rawCount
    ) {
        $this->groupCount = \count($this->groups);
        $this->hasResults = $this->groups !== [];
        $this->hasActiveFilters = $this->activeTargetType !== 'all'
            || $this->activeDomain !== 'all'
            || $this->activeStatusCode !== 'all'
            || $this->activeImpact !== 'all';
    }

    /**
     * @return array<ResultItemGroupView>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    public function getModeOptions(): array
    {
        return $this->modeOptions;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    public function getTargetTypeOptions(): array
    {
        return $this->targetTypeOptions;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    public function getDomainOptions(): array
    {
        return $this->domainOptions;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    public function getStatusOptions(): array
    {
        return $this->statusOptions;
    }

    /**
     * @return array<ResultItemFilterOptionView>
     */
    public function getImpactOptions(): array
    {
        return $this->impactOptions;
    }

    public function getActiveMode(): string
    {
        return $this->activeMode;
    }

    public function getActiveTargetType(): string
    {
        return $this->activeTargetType;
    }

    public function getActiveDomain(): string
    {
        return $this->activeDomain;
    }

    public function getActiveStatusCode(): string
    {
        return $this->activeStatusCode;
    }

    public function getActiveImpact(): string
    {
        return $this->activeImpact;
    }

    public function getRawCount(): int
    {
        return $this->rawCount;
    }

    public function getGroupCount(): int
    {
        return $this->groupCount;
    }

    public function hasResults(): bool
    {
        return $this->hasResults;
    }

    public function hasActiveFilters(): bool
    {
        return $this->hasActiveFilters;
    }
}
