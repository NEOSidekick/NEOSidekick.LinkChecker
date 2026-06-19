<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Presentation;

use DateTimeInterface;
use NEOSidekick\LinkChecker\Domain\Model\ResultItem;

final class ResultItemView
{
    public function __construct(
        private readonly ResultItem $resultItem,
        private readonly string $sourceLabel,
        private readonly string $sourceFrontendUri,
        private readonly ?string $sourceEditUri,
        private readonly string $targetLabel,
        private readonly ?string $targetUri
    ) {
    }

    public function getResultItem(): ResultItem
    {
        return $this->resultItem;
    }

    public function getDomain(): string
    {
        return $this->resultItem->getDomain();
    }

    public function getSourceLabel(): string
    {
        return $this->sourceLabel;
    }

    public function getSourceFrontendUri(): string
    {
        return $this->sourceFrontendUri;
    }

    public function getSourceEditUri(): ?string
    {
        return $this->sourceEditUri;
    }

    public function getTargetLabel(): string
    {
        return $this->targetLabel;
    }

    public function getTarget(): string
    {
        return $this->resultItem->getTarget();
    }

    public function getTargetUri(): ?string
    {
        return $this->targetUri;
    }

    public function getTargetFallbackLabel(): string
    {
        return $this->resultItem->getTarget();
    }

    public function getStatusCode(): int
    {
        return $this->resultItem->getStatusCode();
    }

    public function getCheckedAt(): DateTimeInterface
    {
        return $this->resultItem->getCheckedAt();
    }

    public function isInternalTarget(): bool
    {
        $target = $this->resultItem->getTarget();
        return str_starts_with($target, 'node://') || str_starts_with($target, '/');
    }
}
