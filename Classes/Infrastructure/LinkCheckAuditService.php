<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class LinkCheckAuditService
{
    private const TABLE_NAME = 'neosidekick_linkchecker_domain_model_resultitem';

    /**
     * @var EntityManagerInterface
     * @Flow\Inject
     */
    protected $entityManager;

    /**
     * @return array{
     *     totalRows: int,
     *     activeRows: int,
     *     ignoredRows: int,
     *     distinctFingerprints: int,
     *     duplicateFingerprintGroups: int,
     *     activeTargetLevelIssues: int,
     *     activeServerErrorRows: int
     * }
     */
    public function createAudit(): array
    {
        $connection = $this->entityManager->getConnection();
        $tableName = self::TABLE_NAME;

        return [
            'totalRows' => (int)$connection->fetchOne("SELECT COUNT(*) FROM {$tableName}"),
            'activeRows' => (int)$connection->fetchOne("SELECT COUNT(*) FROM {$tableName} WHERE `ignore` = 0"),
            'ignoredRows' => (int)$connection->fetchOne("SELECT COUNT(*) FROM {$tableName} WHERE `ignore` = 1"),
            'distinctFingerprints' => (int)$connection->fetchOne(
                "SELECT COUNT(DISTINCT fingerprint) FROM {$tableName} WHERE fingerprint IS NOT NULL AND fingerprint <> ''"
            ),
            'duplicateFingerprintGroups' => (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM (
                    SELECT fingerprint
                    FROM {$tableName}
                    WHERE fingerprint IS NOT NULL AND fingerprint <> ''
                    GROUP BY fingerprint
                    HAVING COUNT(*) > 1
                ) duplicate_fingerprints"
            ),
            'activeTargetLevelIssues' => (int)$connection->fetchOne(
                "SELECT COUNT(DISTINCT CONCAT_WS('|', COALESCE(domain, ''), COALESCE(target, ''), COALESCE(statuscode, '')))
                FROM {$tableName}
                WHERE `ignore` = 0"
            ),
            'activeServerErrorRows' => (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM {$tableName} WHERE `ignore` = 0 AND statuscode >= 500"
            ),
        ];
    }
}
