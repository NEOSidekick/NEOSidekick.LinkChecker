<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619120000 extends AbstractMigration
{
    private const TABLE_NAME = 'neosidekick_linkchecker_domain_model_resultitem';

    public function getDescription(): string
    {
        return 'Add a classification state (broken/warning) to link checker results for false-positive reduction';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform() instanceof AbstractPlatform
            && $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySql and MariaDB.'
        );

        if (!$this->tableExists()) {
            return;
        }

        if (!$this->columnExists('state')) {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD state VARCHAR(20) DEFAULT NULL AFTER statuscode',
                self::TABLE_NAME
            ));
        }

        // Classify existing findings: auth/bot/rate-limit codes, redirects and invalid phone numbers
        // become warnings, everything else remains a hard broken link.
        $this->connection->executeStatement(sprintf(
            'UPDATE %s SET state = :warning WHERE state IS NULL AND (statuscode IN (401, 403, 429, 490) OR (statuscode >= 300 AND statuscode < 400))',
            self::TABLE_NAME
        ), ['warning' => 'warning']);

        $this->connection->executeStatement(sprintf(
            'UPDATE %s SET state = :broken WHERE state IS NULL',
            self::TABLE_NAME
        ), ['broken' => 'broken']);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform() instanceof AbstractPlatform
            && $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySql and MariaDB.'
        );

        if (!$this->tableExists()) {
            return;
        }

        if ($this->columnExists('state')) {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s DROP state',
                self::TABLE_NAME
            ));
        }
    }

    private function columnExists(string $columnName): bool
    {
        return (bool)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName AND COLUMN_NAME = :columnName',
            ['tableName' => self::TABLE_NAME, 'columnName' => $columnName]
        );
    }

    private function tableExists(): bool
    {
        return (bool)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName',
            ['tableName' => self::TABLE_NAME]
        );
    }
}
