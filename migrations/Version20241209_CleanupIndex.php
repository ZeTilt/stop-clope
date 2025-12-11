<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove redundant index on wake_up table and add composite index for better performance
 */
final class Version20241209_CleanupIndex extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove redundant idx_wakeup_date index (UNIQUE already provides indexing) and add composite index for cigarette';
    }

    public function up(Schema $schema): void
    {
        // Remove redundant index (UNIQUE constraint already provides the same functionality)
        $this->addSql('DROP INDEX idx_wakeup_date ON wake_up');

        // Add composite index for better performance on daily stats queries
        $this->addSql('CREATE INDEX idx_cigarette_smoked_created ON cigarette (smoked_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        // Re-add the redundant index
        $this->addSql('CREATE INDEX idx_wakeup_date ON wake_up (date)');

        // Remove composite index
        $this->addSql('DROP INDEX idx_cigarette_smoked_created ON cigarette');
    }
}
