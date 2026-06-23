<?php

namespace NEOSidekick\LinkChecker\Domain\Model;

use Neos\Flow\Persistence\QueryResultInterface;

interface ResultItemRepositoryInterface
{
    public function findAll(): QueryResultInterface;

    public function findFirstNonIgnored(int $limit): QueryResultInterface;

    public function findFilteredNonIgnored(
        int $limit,
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact
    ): QueryResultInterface;

    public function countFilteredNonIgnored(string $targetType, string $domain, string $statusCode, string $impact): int;

    public function remove(ResultItem $resultItem): void;

    /**
     * @return ResultItem[]
     */
    public function findByDomainTargetAndStatusCode(string $domain, string $target, int $statusCode): array;

    public function truncate(): void;

    public function removeAllNonIgnored(): void;

    public function ignore(ResultItem $resultItem): void;

    public function add(ResultItem $resultItem): void;
}
