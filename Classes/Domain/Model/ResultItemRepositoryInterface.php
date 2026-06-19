<?php

namespace NEOSidekick\LinkChecker\Domain\Model;

use Neos\Flow\Persistence\QueryResultInterface;

interface ResultItemRepositoryInterface
{
    public function findAll(): QueryResultInterface;

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
