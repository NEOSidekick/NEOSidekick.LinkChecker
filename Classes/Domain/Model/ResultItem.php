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
     * @var string|null
     * @ORM\Column(length=64, nullable=false, unique=true)
     */
    protected ?string $fingerprint = null;

    /**
     * @var string
     */
    protected string $domain;

    /**
     * @var string|null
     * @ORM\Column(length=2000, nullable=true)
     */
    protected ?string $source = null;

    /**
     * @var string|null
     * @ORM\Column(length=2000, nullable=true)
     */
    protected ?string $sourcePath = null;

    /**
     * @var string
     * @ORM\Column(length=2000, nullable=false)
     */
    protected string $target;

    /**
     * @var string|null
     * @ORM\Column(length=2000, nullable=true)
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

    public static function createFingerprint(
        string $domain,
        ?string $source,
        ?string $sourcePath,
        string $target,
        int $statusCode
    ): string {
        return hash('sha256', json_encode([
            'domain' => self::normalizeDomain($domain),
            'source' => self::normalizeSourceIdentity($source, $sourcePath),
            'target' => self::normalizeUriLikeValue($target),
            'statusCode' => $statusCode,
        ], JSON_THROW_ON_ERROR));
    }

    public static function createIssueFingerprint(string $domain, string $target, int $statusCode): string
    {
        return hash('sha256', json_encode([
            'domain' => self::normalizeDomain($domain),
            'target' => self::normalizeUriLikeValue($target),
            'statusCode' => $statusCode,
        ], JSON_THROW_ON_ERROR));
    }

    public function refreshFingerprint(): void
    {
        $this->fingerprint = self::createFingerprint(
            $this->domain,
            $this->source,
            $this->sourcePath,
            $this->target,
            $this->statusCode
        );
    }

    public function getFingerprint(): string
    {
        if ($this->fingerprint === null || $this->fingerprint === '') {
            $this->refreshFingerprint();
        }

        return $this->fingerprint;
    }

    public function setFingerprint(?string $fingerprint = null): void
    {
        $this->fingerprint = $fingerprint;
    }

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

    public function mergeFrom(ResultItem $incoming): void
    {
        if ($incoming->getCreatedAt() < $this->getCreatedAt()) {
            $this->setCreatedAt($incoming->getCreatedAt());
        }

        if ($incoming->getCheckedAt() > $this->getCheckedAt()) {
            $this->setCheckedAt($incoming->getCheckedAt());
        }

        if (!$this->hasValue($this->source) && $this->hasValue($incoming->getSource())) {
            $this->setSource($incoming->getSource());
        }

        if (!$this->hasValue($this->sourcePath) && $this->hasValue($incoming->getSourcePath())) {
            $this->setSourcePath($incoming->getSourcePath());
        }

        if (!$this->hasValue($this->targetPath) && $this->hasValue($incoming->getTargetPath())) {
            $this->setTargetPath($incoming->getTargetPath());
        }

        if ($incoming->getIgnore()) {
            $this->setIgnore(true);
        }

        $this->refreshFingerprint();
    }

    public function jsonSerialize(): array
    {
        return [
            'fingerprint' => $this->getFingerprint(),
            'issueFingerprint' => self::createIssueFingerprint($this->domain, $this->target, $this->statusCode),
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

    private static function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    private static function normalizeSourceIdentity(?string $source, ?string $sourcePath): string
    {
        if (self::hasStaticValue($source)) {
            return 'source:' . strtolower(trim($source));
        }

        return 'sourcePath:' . self::normalizeUriLikeValue($sourcePath ?? '');
    }

    private static function normalizeUriLikeValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (str_starts_with(strtolower($value), 'node://')) {
            return strtolower($value);
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $value;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $user = $parts['user'] ?? null;
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== null ? $user . $pass . '@' : '';

        $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $portPart = $port !== null && !$defaultPort ? ':' . $port : '';

        return sprintf('%s://%s%s%s%s%s', $scheme, $auth, $host, $portPart, $path, $query);
    }

    private function hasValue(?string $value): bool
    {
        return self::hasStaticValue($value);
    }

    private static function hasStaticValue(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
