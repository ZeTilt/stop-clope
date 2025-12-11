<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251211082959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE daily_score (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, score INT NOT NULL, cigarette_count INT NOT NULL, streak INT NOT NULL, average_interval DOUBLE PRECISION DEFAULT NULL, calculated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_B2B85685A76ED395 (user_id), INDEX idx_daily_score_date (date), UNIQUE INDEX unique_user_date (user_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE daily_score ADD CONSTRAINT FK_B2B85685A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_score DROP FOREIGN KEY FK_B2B85685A76ED395');
        $this->addSql('DROP TABLE daily_score');
    }
}
