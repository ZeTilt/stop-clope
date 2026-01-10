<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260110090612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v2.0 - Add UserState, ActiveBonus entities and DailyScore fields for gamification';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE active_bonuses (id INT AUTO_INCREMENT NOT NULL, bonus_type VARCHAR(50) NOT NULL, bonus_value DOUBLE PRECISION NOT NULL, source_badge VARCHAR(100) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_67973CCA76ED395 (user_id), INDEX idx_active_bonus_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_states (id INT AUTO_INCREMENT NOT NULL, shields_count INT NOT NULL, permanent_multiplier DOUBLE PRECISION NOT NULL, current_rank VARCHAR(50) NOT NULL, total_score INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_54906C70A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE active_bonuses ADD CONSTRAINT FK_67973CCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_states ADD CONSTRAINT FK_54906C70A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE daily_score ADD is_maintenance_day TINYINT NOT NULL, ADD multiplier_applied DOUBLE PRECISION DEFAULT NULL');

        // Create UserState for each existing User
        $this->addSql('INSERT INTO user_states (user_id, shields_count, permanent_multiplier, current_rank, total_score) SELECT id, 0, 0.0, \'fumeur\', 0 FROM user');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE active_bonuses DROP FOREIGN KEY FK_67973CCA76ED395');
        $this->addSql('ALTER TABLE user_states DROP FOREIGN KEY FK_54906C70A76ED395');
        $this->addSql('DROP TABLE active_bonuses');
        $this->addSql('DROP TABLE user_states');
        $this->addSql('ALTER TABLE daily_score DROP is_maintenance_day, DROP multiplier_applied');
    }
}
