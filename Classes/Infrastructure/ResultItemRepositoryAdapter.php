<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use NEOSidekick\LinkChecker\Domain\Model\ResultItem;
use NEOSidekick\LinkChecker\Domain\Model\ResultItemRepositoryInterface;
use NEOSidekick\LinkChecker\Presentation\ResultItemGroupingService;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Persistence\Repository;
use Throwable;

/**
 * @Flow\Scope("singleton")
 */
class ResultItemRepositoryAdapter extends Repository implements ResultItemRepositoryInterface
{
    const ENTITY_CLASSNAME = ResultItem::class;
    private const TABLE_NAME = 'neosidekick_linkchecker_domain_model_resultitem';

    /**
     * @var array<string, ResultItem>
     */
    private array $resultItemsByFingerprint = [];

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

    public function findAll(): QueryResultInterface
    {
        $this->ensureDatabaseConnection();

        $query = $this->createQuery();
        $query->matching($query->equals('ignore', 0));
        $query->setOrderings(
            [
                'source' => QueryInterface::ORDER_ASCENDING,
            ]
        );
        return $query->execute();
    }

    public function findFirstNonIgnored(int $limit): QueryResultInterface
    {
        $this->ensureDatabaseConnection();

        $query = $this->createQuery();
        $query->matching($query->equals('ignore', 0));
        $query->setOrderings(
            [
                'source' => QueryInterface::ORDER_ASCENDING,
            ]
        );
        $query->setLimit(max(1, $limit));

        return $query->execute();
    }

    public function findFilteredNonIgnored(
        int $limit,
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact
    ): QueryResultInterface
    {
        $this->ensureDatabaseConnection();

        if ($impact !== ResultItemGroupingService::IMPACT_ALL) {
            return $this->findFilteredNonIgnoredByImpact($limit, $targetType, $domain, $statusCode, $impact);
        }

        $query = $this->createQuery();
        $query->matching($query->logicalAnd($this->createBackendFilterConstraints($query, $targetType, $domain, $statusCode)));
        $query->setOrderings(
            [
                'source' => QueryInterface::ORDER_ASCENDING,
            ]
        );
        $query->setLimit(max(1, $limit));

        return $query->execute();
    }

    public function countFilteredNonIgnored(string $targetType, string $domain, string $statusCode, string $impact): int
    {
        $this->ensureDatabaseConnection();

        if ($impact !== ResultItemGroupingService::IMPACT_ALL) {
            return $this->countFilteredNonIgnoredByImpact($targetType, $domain, $statusCode, $impact);
        }

        $query = $this->createQuery();
        $query->matching($query->logicalAnd($this->createBackendFilterConstraints($query, $targetType, $domain, $statusCode)));

        return $query->count();
    }

    public function remove($resultItem): void
    {
        parent::remove($resultItem);
        $this->flushBackendStatisticsCache();
    }

    public function findByDomainTargetAndStatusCode(string $domain, string $target, int $statusCode): array
    {
        $this->ensureDatabaseConnection();

        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd([
                $query->equals('ignore', false),
                $query->equals('domain', $domain),
                $query->equals('target', $target),
                $query->equals('statusCode', $statusCode),
            ])
        );

        return $query->execute()->toArray();
    }

    public function truncate(): void
    {
        $this->resultItemsByFingerprint = [];
        $this->ensureDatabaseConnection();

        // https://neos-project.slack.com/archives/C04V4C6B0/p1668168503014459
        $qB = $this->entityManager->createQueryBuilder()
            ->delete(ResultItem::class);

        $query = $qB->getQuery();
        $query->execute();
        $this->flushBackendStatisticsCache();
    }

    public function removeAllNonIgnored(): void
    {
        $this->resultItemsByFingerprint = [];
        $this->ensureDatabaseConnection();

        $query = $this->createQuery();
        $query->matching($query->equals('ignore', false));
        $resultItems = $query->execute();
        foreach ($resultItems as $resultItem) {
            $this->remove($resultItem);
        }
        $this->flushBackendStatisticsCache();
    }

    /**
     * @throws IllegalObjectTypeException
     */
    public function ignore(ResultItem $resultItem): void
    {
        $resultItem->setIgnore(true);
        $this->update($resultItem);
        $this->flushBackendStatisticsCache();
    }

    /**
     * @throws IllegalObjectTypeException
     */
    public function add($resultItem): void
    {
        $this->ensureDatabaseConnection();

        $resultItem->refreshFingerprint();
        $fingerprint = $resultItem->getFingerprint();

        $existingResultItem = $this->resultItemsByFingerprint[$fingerprint] ?? $this->findOneByFingerprint($fingerprint);

        if ($existingResultItem instanceof ResultItem) {
            $existingResultItem->mergeFrom($resultItem);
            // Re-index by the current fingerprint, as mergeFrom() may enrich
            // identity-relevant fields and recompute it.
            $this->resultItemsByFingerprint[$existingResultItem->getFingerprint()] = $existingResultItem;
            $this->update($existingResultItem);
            $this->persistAllWithFreshConnection();
            $this->flushBackendStatisticsCache();
            return;
        }

        $this->resultItemsByFingerprint[$fingerprint] = $resultItem;
        parent::add($resultItem);
        // Persist immediately so the unique fingerprint is enforced incrementally:
        // findOneByFingerprint() only sees flushed rows, so without this a later
        // result with the same fingerprint (e.g. two URLs that normalize equally)
        // would schedule a second insert and violate the unique index on flush.
        // add() is only called for broken links, so the number of flushes stays small.
        $this->persistAllWithFreshConnection();
        $this->flushBackendStatisticsCache();
    }

    private function findOneByFingerprint(string $fingerprint, bool $cacheResult = false): ?ResultItem
    {
        $this->ensureDatabaseConnection();

        try {
            return $this->executeFindOneByFingerprint($fingerprint, $cacheResult);
        } catch (Throwable $exception) {
            $this->reconnectDatabase();

            return $this->executeFindOneByFingerprint($fingerprint, $cacheResult);
        }
    }

    private function executeFindOneByFingerprint(string $fingerprint, bool $cacheResult): ?ResultItem
    {
        $query = $this->createQuery();

        return $query
            ->matching($query->equals('fingerprint', $fingerprint))
            ->execute($cacheResult)
            ->getFirst();
    }

    private function persistAllWithFreshConnection(): void
    {
        $this->ensureDatabaseConnection();
        $this->persistenceManager->persistAll();
    }

    private function ensureDatabaseConnection(): void
    {
        $connection = $this->entityManager->getConnection();

        try {
            if (!$connection->isConnected()) {
                $connection->connect();
                return;
            }

            $connection->executeQuery('SELECT 1')->free();
        } catch (Throwable $exception) {
            $this->reconnectDatabase();
        }
    }

    private function reconnectDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->close();
        $connection->connect();
    }

    private function createBackendFilterConstraints($query, string $targetType, string $domain, string $statusCode): array
    {
        $constraints = [$query->equals('ignore', 0)];

        if ($targetType !== 'all') {
            $constraints[] = $this->targetTypeConstraint($query, $targetType);
        }

        if ($domain !== 'all') {
            $constraints[] = $query->equals('domain', $domain);
        }

        if ($statusCode !== 'all') {
            $constraints[] = $query->equals('statusCode', (int)$statusCode);
        }

        return $constraints;
    }

    private function findFilteredNonIgnoredByImpact(
        int $limit,
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact
    ): QueryResultInterface
    {
        $fingerprints = $this->findFingerprintsByBackendSqlFilters($limit, $targetType, $domain, $statusCode, $impact);
        $query = $this->createQuery();

        if ($fingerprints === []) {
            $query->matching($query->equals('fingerprint', '__no_matching_result_item__'));
            return $query->execute();
        }

        $query->matching($query->in('fingerprint', $fingerprints));
        $query->setOrderings(
            [
                'source' => QueryInterface::ORDER_ASCENDING,
            ]
        );

        return $query->execute();
    }

    private function countFilteredNonIgnoredByImpact(
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact
    ): int
    {
        [$innerWhere, $outerWhere, $params] = $this->backendSqlFilterParts($targetType, $domain, $statusCode);

        return (int)$this->entityManager->getConnection()->fetchOne(sprintf(
            'SELECT COUNT(*)
            FROM %1$s r
            INNER JOIN (
                SELECT domain, target, statuscode, COUNT(DISTINCT %2$s) AS affected_source_count
                FROM %1$s i
                WHERE %3$s
                GROUP BY domain, target, statuscode
                HAVING %4$s
            ) issues ON issues.domain = r.domain AND issues.target = r.target AND issues.statuscode = r.statuscode
            WHERE %5$s',
            self::TABLE_NAME,
            $this->sourceKeySqlExpression('i'),
            $innerWhere,
            $this->impactHavingSql($impact),
            $outerWhere
        ), $params);
    }

    private function findFingerprintsByBackendSqlFilters(
        int $limit,
        string $targetType,
        string $domain,
        string $statusCode,
        string $impact
    ): array
    {
        [$innerWhere, $outerWhere, $params] = $this->backendSqlFilterParts($targetType, $domain, $statusCode);
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(sprintf(
            'SELECT r.fingerprint
            FROM %1$s r
            INNER JOIN (
                SELECT domain, target, statuscode, COUNT(DISTINCT %2$s) AS affected_source_count
                FROM %1$s i
                WHERE %3$s
                GROUP BY domain, target, statuscode
                HAVING %4$s
            ) issues ON issues.domain = r.domain AND issues.target = r.target AND issues.statuscode = r.statuscode
            WHERE %5$s
            ORDER BY r.source ASC
            LIMIT %6$d',
            self::TABLE_NAME,
            $this->sourceKeySqlExpression('i'),
            $innerWhere,
            $this->impactHavingSql($impact),
            $outerWhere,
            max(1, $limit)
        ), $params);

        return array_values(array_filter(array_map(fn (array $row) => (string)$row['fingerprint'], $rows)));
    }

    private function backendSqlFilterParts(string $targetType, string $domain, string $statusCode): array
    {
        $innerWhere = ['i.`ignore` = 0'];
        $outerWhere = ['r.`ignore` = 0'];
        $params = [];

        if ($targetType !== 'all') {
            $innerWhere[] = $this->targetTypeSqlCondition('i', $targetType);
            $outerWhere[] = $this->targetTypeSqlCondition('r', $targetType);
        }

        if ($domain !== 'all') {
            $innerWhere[] = 'i.domain = :domain';
            $outerWhere[] = 'r.domain = :domain';
            $params['domain'] = $domain;
        }

        if ($statusCode !== 'all') {
            $innerWhere[] = 'i.statuscode = :statusCode';
            $outerWhere[] = 'r.statuscode = :statusCode';
            $params['statusCode'] = (int)$statusCode;
        }

        return [implode(' AND ', $innerWhere), implode(' AND ', $outerWhere), $params];
    }

    private function targetTypeSqlCondition(string $alias, string $targetType): string
    {
        $internal = sprintf(
            "(%1\$s.targetpath IS NOT NULL AND TRIM(%1\$s.targetpath) <> '') OR LOWER(%1\$s.target) LIKE 'node://%%' OR %1\$s.target LIKE '/%%'",
            $alias
        );
        $externalUri = sprintf(
            "(LOWER(%1\$s.target) LIKE 'http://%%' OR LOWER(%1\$s.target) LIKE 'https://%%')",
            $alias
        );

        return match ($targetType) {
            'internalNode' => '(' . $internal . ')',
            'externalUrl' => '(NOT (' . $internal . ') AND ' . $externalUri . ')',
            'otherProtocol' => '(NOT (' . $internal . ') AND NOT ' . $externalUri . ')',
            default => '1 = 1',
        };
    }

    private function impactHavingSql(string $impact): string
    {
        return match ($impact) {
            ResultItemGroupingService::IMPACT_10_PLUS => 'affected_source_count >= 10',
            ResultItemGroupingService::IMPACT_3_PLUS => 'affected_source_count >= 3 AND affected_source_count < 10',
            ResultItemGroupingService::IMPACT_LOW => 'affected_source_count < 3',
            default => '1 = 1',
        };
    }

    private function sourceKeySqlExpression(string $alias): string
    {
        return sprintf("CONCAT(%1\$s.domain, '|', COALESCE(NULLIF(%1\$s.source, ''), NULLIF(%1\$s.sourcepath, ''), ''))", $alias);
    }

    private function targetTypeConstraint($query, string $targetType)
    {
        $internalTargetConstraint = $query->logicalOr([
            $query->logicalNot($query->equals('targetPath', null)),
            $query->like('target', 'node://%', false),
            $query->like('target', '/%', false),
        ]);

        $externalTargetConstraint = $query->logicalAnd([
            $query->logicalNot($internalTargetConstraint),
            $query->logicalOr([
                $query->like('target', 'http://%', false),
                $query->like('target', 'https://%', false),
            ]),
        ]);

        return match ($targetType) {
            'internalNode' => $internalTargetConstraint,
            'externalUrl' => $externalTargetConstraint,
            'otherProtocol' => $query->logicalAnd([
                $query->logicalNot($internalTargetConstraint),
                $query->logicalNot($externalTargetConstraint),
            ]),
            default => $query->equals('ignore', 0),
        };
    }

    private function flushBackendStatisticsCache(): void
    {
        $this->cacheManager->getCache('NEOSidekick_LinkChecker_BackendStatistics')->flush();
    }
}
