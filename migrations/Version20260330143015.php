<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260330143015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add effective_date column to cigarette (journée = réveil-à-réveil)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cigarette ADD effective_date DATE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_effective_date_user ON cigarette (effective_date, user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_effective_date_user ON cigarette');
        $this->addSql('ALTER TABLE cigarette DROP effective_date');
    }
}
