<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

final class ResultItemFilterOptionView
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $label,
        public readonly ?string $translationId = null,
        public readonly int $count = 0
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTranslationId(): ?string
    {
        return $this->translationId;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
