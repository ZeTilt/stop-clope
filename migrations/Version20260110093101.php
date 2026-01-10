<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260110093101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add target_interval to daily_score and current_target_interval to user_states for story 2.3';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_score ADD target_interval DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE user_states ADD current_target_interval DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_score DROP target_interval');
        $this->addSql('ALTER TABLE user_states DROP current_target_interval');
    }
}
