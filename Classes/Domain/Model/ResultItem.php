<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Domain\Model;

use DateTimeInterface;
use Neos\Flow\Annotations as Flow;
use Doctrine\ORM\Mapping as ORM;

/**
 * @Flow\Entity
 */
class ResultItem implements \JsonSerializable
{
    /**
     * @var string
     */
    protected string $domain;

    /**
     * @var string|null
     */
    protected ?string $source = null;

    /**
     * @var string|null
     */
    protected ?string $sourcePath = null;

    /**
     * @var string
     */
    protected string $target;

    /**
     * @var string|null
     */
    protected ?string $targetPath = null;

    /**
     * @var string|null
     * @Flow\Transient
     */
    protected ?string $targetPageTitle = null;

    /**
     * @var integer
     */
    protected int $statusCode;

    /**
     * @var boolean
     * @ORM\Column(name="`ignore`")
     * ignore is a reserved mysql word, therefor escape it manually
     */
    protected bool $ignore = false;

    /**
     * @var DateTimeInterface
     */
    protected DateTimeInterface $createdAt;

    /**
     * @var DateTimeInterface
     */
    protected DateTimeInterface $checkedAt;

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): void
    {
        $this->domain = $domain;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source = null): void
    {
        $this->source = $source;
    }

    public function getSourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function setSourcePath(?string $sourcePath = null): void
    {
        $this->sourcePath = $sourcePath;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): void
    {
        $this->target = $target;
    }

    public function getTargetPath(): ?string
    {
        return $this->targetPath;
    }

    public function setTargetPath(?string $targetPath): void
    {
        $this->targetPath = $targetPath;
    }

    public function getTargetPageTitle(): ?string
    {
        return $this->targetPageTitle;
    }

    public function setTargetPageTitle(?string $targetPageTitle): void
    {
        $this->targetPageTitle = $targetPageTitle;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function getIgnore(): bool
    {
        return $this->ignore;
    }

    public function setIgnore(bool $ignore): void
    {
        $this->ignore = $ignore;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCheckedAt(): DateTimeInterface
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(DateTimeInterface $checkedAt): void
    {
        $this->checkedAt = $checkedAt;
    }

    public function jsonSerialize(): array
    {
        return [
            'domain' => $this->getDomain(),
            'source' => $this->getSource(),
            'sourcePath' => $this->getSourcePath(),
            'target' => $this->getTarget(),
            'targetPath' => $this->getTargetPath(),
            'targetPageTitle' => $this->getTargetPageTitle(),
            'statusCode' => $this->getStatusCode(),
            'ignore' => $this->getIgnore(),
            'createdAt' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
            'checkedAt' => $this->getCheckedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
