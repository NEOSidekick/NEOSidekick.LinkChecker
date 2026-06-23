<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;

/**
 * @Flow\Scope("singleton")
 */
class BackendStatisticsService
{
    private const TABLE_NAME = 'neosidekick_linkchecker_domain_model_resultitem';
    private const CACHE_IDENTIFIER = 'statistics';

    /**
     * @var EntityManagerInterface
     * @Flow\Inject
     */
    protected $entityManager;

    /**
     * @var CacheManager
     * @Flow\Inject
     */
    protected $cacheManager;

    protected VariableFrontend $cache;

    public function initializeObject(): void
    {
        $this->cache = $this->cacheManager->getCache('NEOSidekick_LinkChecker_BackendStatistics');
    }

    public function create(): array
    {
        if ($this->cache->has(self::CACHE_IDENTIFIER)) {
            return $this->cache->get(self::CACHE_IDENTIFIER);
        }

        $statistics = [
            'totalRows' => $this->countTotalRows(),
            'totalIssues' => $this->countTotalIssues(),
            'affectedBrokenSourcePageCount' => $this->countAffectedBrokenSourcePages(),
            'targetTypeIssueCounts' => $this->countIssuesByTargetType(),
            'domainIssueCounts' => $this->countIssuesByDomain(),
            'statusIssueCounts' => $this->countIssuesByStatusCode(),
            'impactIssueCounts' => $this->countIssuesByImpact(),
        ];

        $this->cache->set(self::CACHE_IDENTIFIER, $statistics);

        return $statistics;
    }

    private function countTotalRows(): int
    {
        return (int)$this->entityManager->getConnection()->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE `ignore` = 0',
            self::TABLE_NAME
        ));
    }

    private function countTotalIssues(): int
    {
        return (int)$this->entityManager->getConnection()->fetchOne(sprintf(
            'SELECT COUNT(*) FROM (
                SELECT domain, target, statuscode
                FROM %s
                WHERE `ignore` = 0
                GROUP BY domain, target, statuscode
            ) issues',
            self::TABLE_NAME
        ));
    }

    private function countAffectedBrokenSourcePages(): int
    {
        return (int)$this->entityManager->getConnection()->fetchOne(sprintf(
            "SELECT COUNT(DISTINCT %s) FROM %s WHERE `ignore` = 0 AND COALESCE(state, 'broken') = 'broken'",
            $this->sourceKeyExpression(),
            self::TABLE_NAME
        ));
    }

    private function countIssuesByTargetType(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(sprintf(
            'SELECT target_type, COUNT(*) AS issue_count
            FROM (
                SELECT domain, target, statuscode, %s AS target_type
                FROM %s
                WHERE `ignore` = 0
                GROUP BY domain, target, statuscode, target_type
            ) issues
            GROUP BY target_type',
            $this->targetTypeExpression(),
            self::TABLE_NAME
        ));

        return $this->rowsToIntegerMap($rows, 'target_type');
    }

    private function countIssuesByDomain(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(sprintf(
            'SELECT domain, COUNT(*) AS issue_count
            FROM (
                SELECT domain, target, statuscode
                FROM %s
                WHERE `ignore` = 0
                GROUP BY domain, target, statuscode
            ) issues
            GROUP BY domain
            ORDER BY domain ASC',
            self::TABLE_NAME
        ));

        return $this->rowsToIntegerMap($rows, 'domain');
    }

    private function countIssuesByStatusCode(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(sprintf(
            'SELECT statuscode, COUNT(*) AS issue_count
            FROM (
                SELECT domain, target, statuscode
                FROM %s
                WHERE `ignore` = 0
                GROUP BY domain, target, statuscode
            ) issues
            GROUP BY statuscode
            ORDER BY statuscode ASC',
            self::TABLE_NAME
        ));

        return $this->rowsToIntegerMap($rows, 'statuscode');
    }

    private function countIssuesByImpact(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(sprintf(
            'SELECT affected_source_count
            FROM (
                SELECT domain, target, statuscode, COUNT(DISTINCT %s) AS affected_source_count
                FROM %s
                WHERE `ignore` = 0
                GROUP BY domain, target, statuscode
            ) issues',
            $this->sourceKeyExpression(),
            self::TABLE_NAME
        ));

        $counts = [
            '10Plus' => 0,
            '3Plus' => 0,
            'low' => 0,
        ];

        foreach ($rows as $row) {
            $affectedSourceCount = (int)$row['affected_source_count'];
            if ($affectedSourceCount >= 10) {
                $counts['10Plus']++;
            } elseif ($affectedSourceCount >= 3) {
                $counts['3Plus']++;
            } else {
                $counts['low']++;
            }
        }

        return $counts;
    }

    private function rowsToIntegerMap(array $rows, string $keyColumn): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row[$keyColumn]] = (int)$row['issue_count'];
        }

        return $counts;
    }

    private function targetTypeExpression(): string
    {
        return "CASE
            WHEN targetpath IS NOT NULL AND TRIM(targetpath) <> '' THEN 'internalNode'
            WHEN LOWER(target) LIKE 'node://%' OR target LIKE '/%' THEN 'internalNode'
            WHEN LOWER(target) LIKE 'http://%' OR LOWER(target) LIKE 'https://%' THEN 'externalUrl'
            ELSE 'otherProtocol'
        END";
    }

    private function sourceKeyExpression(): string
    {
        return "CONCAT(domain, '|', COALESCE(NULLIF(source, ''), NULLIF(sourcepath, ''), ''))";
    }
}
