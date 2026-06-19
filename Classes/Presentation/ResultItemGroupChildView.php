<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

final class ResultItemGroupChildView
{
    public readonly int $duplicateCount;
    public readonly bool $hasDuplicates;

    public function __construct(
        public readonly ResultItemView $link,
        public readonly int $occurrenceCount
    ) {
        $this->duplicateCount = max(0, $this->occurrenceCount - 1);
        $this->hasDuplicates = $this->duplicateCount > 0;
    }

    public function getLink(): ResultItemView
    {
        return $this->link;
    }

    public function getOccurrenceCount(): int
    {
        return $this->occurrenceCount;
    }

    public function getDuplicateCount(): int
    {
        return $this->duplicateCount;
    }

    public function hasDuplicates(): bool
    {
        return $this->hasDuplicates;
    }

    public function getHasDuplicates(): bool
    {
        return $this->hasDuplicates();
    }
}
