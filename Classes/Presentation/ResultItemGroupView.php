<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use DateTimeInterface;

final class ResultItemGroupView
{
    public readonly int $duplicateCount;
    public readonly bool $hasDuplicates;
    public readonly string $domainsLabel;

    /**
     * @param array<ResultItemGroupChildView> $children
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $uri,
        public readonly ?string $secondaryLabel,
        public readonly ?int $statusCode,
        public readonly string $targetType,
        public readonly array $children,
        public readonly int $affectedSourceCount,
        public readonly int $occurrenceCount,
        public readonly array $domains,
        public readonly DateTimeInterface $lastCheckedAt
    ) {
        $this->duplicateCount = max(0, $this->occurrenceCount - \count($this->children));
        $this->hasDuplicates = $this->duplicateCount > 0;
        $this->domainsLabel = $this->createDomainsLabel();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function getSecondaryLabel(): ?string
    {
        return $this->secondaryLabel;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    /**
     * @return array<ResultItemGroupChildView>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getAffectedSourceCount(): int
    {
        return $this->affectedSourceCount;
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

    public function getDomainsLabel(): string
    {
        return $this->domainsLabel;
    }

    private function createDomainsLabel(): string
    {
        $domains = array_values($this->domains);
        sort($domains);

        if (\count($domains) <= 3) {
            return implode(', ', $domains);
        }

        return implode(', ', array_slice($domains, 0, 3)) . ' +' . (\count($domains) - 3);
    }

    public function getLastCheckedAt(): DateTimeInterface
    {
        return $this->lastCheckedAt;
    }
}
