<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618130000 extends AbstractMigration
{
    private const TABLE_NAME = 'neosidekick_linkchecker_domain_model_resultitem';
    private const FINGERPRINT_INDEX_NAME = 'UNIQ_90B6CF92FC0B754A';

    public function getDescription(): string
    {
        return 'Add stable fingerprints to link checker results and deduplicate existing findings';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform() instanceof AbstractPlatform
            && $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySql and MariaDB.'
        );

        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        if (!$this->columnExists('fingerprint')) {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD fingerprint VARCHAR(64) DEFAULT NULL AFTER statuscode',
                self::TABLE_NAME
            ));
        }

        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT persistence_object_identifier, domain, source, sourcepath, target, targetpath, statuscode, `ignore`, createdat, checkedat FROM %s ORDER BY createdat ASC, persistence_object_identifier ASC',
            self::TABLE_NAME
        ));

        $groups = [];
        foreach ($rows as $row) {
            $fingerprint = self::createFingerprint(
                (string)$row['domain'],
                $row['source'] !== null ? (string)$row['source'] : null,
                $row['sourcepath'] !== null ? (string)$row['sourcepath'] : null,
                (string)$row['target'],
                (int)$row['statuscode']
            );
            $row['fingerprint'] = $fingerprint;

            $this->connection->executeStatement(sprintf(
                'UPDATE %s SET fingerprint = :fingerprint WHERE persistence_object_identifier = :identifier',
                self::TABLE_NAME
            ), [
                'fingerprint' => $fingerprint,
                'identifier' => $row['persistence_object_identifier'],
            ]);

            $groups[$fingerprint][] = $row;
        }

        foreach ($groups as $fingerprint => $groupRows) {
            if (count($groupRows) <= 1) {
                continue;
            }

            $survivor = $this->chooseSurvivor($groupRows);
            $merged = $this->mergeRows($survivor, $groupRows, $fingerprint);

            $this->connection->executeStatement(sprintf(
                'UPDATE %s SET domain = :domain, source = :source, sourcepath = :sourcepath, target = :target, targetpath = :targetpath, statuscode = :statuscode, `ignore` = :ignore, createdat = :createdat, checkedat = :checkedat, fingerprint = :fingerprint WHERE persistence_object_identifier = :identifier',
                self::TABLE_NAME
            ), $merged);

            foreach ($groupRows as $row) {
                if ($row['persistence_object_identifier'] === $survivor['persistence_object_identifier']) {
                    continue;
                }

                $this->connection->executeStatement(sprintf(
                    'DELETE FROM %s WHERE persistence_object_identifier = :identifier',
                    self::TABLE_NAME
                ), [
                    'identifier' => $row['persistence_object_identifier'],
                ]);
            }
        }

        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s MODIFY fingerprint VARCHAR(64) NOT NULL',
            self::TABLE_NAME
        ));

        if (!$this->indexExists(self::FINGERPRINT_INDEX_NAME)) {
            $this->connection->executeStatement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (fingerprint)',
                self::FINGERPRINT_INDEX_NAME,
                self::TABLE_NAME
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform() instanceof AbstractPlatform
            && $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySql and MariaDB.'
        );

        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        if ($this->indexExists(self::FINGERPRINT_INDEX_NAME)) {
            $this->connection->executeStatement(sprintf(
                'DROP INDEX %s ON %s',
                self::FINGERPRINT_INDEX_NAME,
                self::TABLE_NAME
            ));
        }

        if ($this->columnExists('fingerprint')) {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s DROP fingerprint',
                self::TABLE_NAME
            ));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function chooseSurvivor(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $leftIgnored = (int)$left['ignore'];
            $rightIgnored = (int)$right['ignore'];
            if ($leftIgnored !== $rightIgnored) {
                return $rightIgnored <=> $leftIgnored;
            }

            return [$left['createdat'], $left['persistence_object_identifier']]
                <=> [$right['createdat'], $right['persistence_object_identifier']];
        });

        return $rows[0];
    }

    /**
     * @param array<string, mixed> $survivor
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function mergeRows(array $survivor, array $rows, string $fingerprint): array
    {
        $merged = [
            'identifier' => $survivor['persistence_object_identifier'],
            'domain' => $survivor['domain'],
            'source' => $survivor['source'],
            'sourcepath' => $survivor['sourcepath'],
            'target' => $survivor['target'],
            'targetpath' => $survivor['targetpath'],
            'statuscode' => $survivor['statuscode'],
            'ignore' => (int)$survivor['ignore'],
            'createdat' => $survivor['createdat'],
            'checkedat' => $survivor['checkedat'],
            'fingerprint' => $fingerprint,
        ];

        foreach ($rows as $row) {
            foreach (['source', 'sourcepath', 'targetpath'] as $fieldName) {
                if (!self::hasValue($merged[$fieldName]) && self::hasValue($row[$fieldName])) {
                    $merged[$fieldName] = $row[$fieldName];
                }
            }

            if ((int)$row['ignore'] === 1) {
                $merged['ignore'] = 1;
            }

            if ((string)$row['createdat'] < (string)$merged['createdat']) {
                $merged['createdat'] = $row['createdat'];
            }

            if ((string)$row['checkedat'] > (string)$merged['checkedat']) {
                $merged['checkedat'] = $row['checkedat'];
            }
        }

        return $merged;
    }

    private function columnExists(string $columnName): bool
    {
        return (bool)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName AND COLUMN_NAME = :columnName',
            ['tableName' => self::TABLE_NAME, 'columnName' => $columnName]
        );
    }

    private function indexExists(string $indexName): bool
    {
        return (bool)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName AND INDEX_NAME = :indexName',
            ['tableName' => self::TABLE_NAME, 'indexName' => $indexName]
        );
    }

    private static function createFingerprint(
        string $domain,
        ?string $source,
        ?string $sourcePath,
        string $target,
        int $statusCode
    ): string {
        return hash('sha256', json_encode([
            'domain' => strtolower(trim($domain)),
            'source' => self::normalizeSourceIdentity($source, $sourcePath),
            'target' => self::normalizeUriLikeValue($target),
            'statusCode' => $statusCode,
        ], JSON_THROW_ON_ERROR));
    }

    private static function normalizeSourceIdentity(?string $source, ?string $sourcePath): string
    {
        if (self::hasValue($source)) {
            return 'source:' . strtolower(trim((string)$source));
        }

        return 'sourcePath:' . self::normalizeUriLikeValue((string)($sourcePath ?? ''));
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

    private static function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
