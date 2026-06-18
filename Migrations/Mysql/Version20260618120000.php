<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618120000 extends AbstractMigration
{
    private const OLD_TABLE_NAME = 'codeq_linkchecker_domain_model_resultitem';
    private const NEW_TABLE_NAME = 'neosidekick_linkchecker_domain_model_resultitem';

    public function getDescription(): string
    {
        return 'Rename CodeQ.LinkChecker result table to NEOSidekick.LinkChecker';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform() instanceof AbstractPlatform
            && $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySql and MariaDB.'
        );

        $this->abortIf(
            $schema->hasTable(self::OLD_TABLE_NAME) && $schema->hasTable(self::NEW_TABLE_NAME),
            sprintf('Both "%s" and "%s" exist. Merge or remove one table before running this migration.', self::OLD_TABLE_NAME, self::NEW_TABLE_NAME)
        );

        if ($schema->hasTable(self::OLD_TABLE_NAME) && !$schema->hasTable(self::NEW_TABLE_NAME)) {
            $this->addSql(sprintf('RENAME TABLE %s TO %s', self::OLD_TABLE_NAME, self::NEW_TABLE_NAME));
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform() instanceof AbstractPlatform
            && $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on MySql and MariaDB.'
        );

        $this->abortIf(
            $schema->hasTable(self::OLD_TABLE_NAME) && $schema->hasTable(self::NEW_TABLE_NAME),
            sprintf('Both "%s" and "%s" exist. Merge or remove one table before reverting this migration.', self::OLD_TABLE_NAME, self::NEW_TABLE_NAME)
        );

        if (!$schema->hasTable(self::OLD_TABLE_NAME) && $schema->hasTable(self::NEW_TABLE_NAME)) {
            $this->addSql(sprintf('RENAME TABLE %s TO %s', self::NEW_TABLE_NAME, self::OLD_TABLE_NAME));
        }
    }
}
