<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251211080405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('DROP INDEX idx_cigarette_smoked_created ON cigarette');
        $this->addSql('ALTER TABLE cigarette ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cigarette ADD CONSTRAINT FK_6B63E1FCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX idx_user_id ON cigarette (user_id)');
        $this->addSql('DROP INDEX UNIQ_name ON settings');
        $this->addSql('ALTER TABLE settings ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE settings ADD CONSTRAINT FK_E545A0C5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_E545A0C5A76ED395 ON settings (user_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_user_setting ON settings (user_id, name)');
        $this->addSql('CREATE INDEX idx_wakeup_date ON wake_up (date)');
        $this->addSql('ALTER TABLE wake_up RENAME INDEX uniq_date TO UNIQ_F0BC394BAA9E377A');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE cigarette DROP FOREIGN KEY FK_6B63E1FCA76ED395');
        $this->addSql('DROP INDEX idx_user_id ON cigarette');
        $this->addSql('ALTER TABLE cigarette DROP user_id');
        $this->addSql('CREATE INDEX idx_cigarette_smoked_created ON cigarette (smoked_at, created_at)');
        $this->addSql('ALTER TABLE settings DROP FOREIGN KEY FK_E545A0C5A76ED395');
        $this->addSql('DROP INDEX IDX_E545A0C5A76ED395 ON settings');
        $this->addSql('DROP INDEX unique_user_setting ON settings');
        $this->addSql('ALTER TABLE settings DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_name ON settings (name)');
        $this->addSql('DROP INDEX idx_wakeup_date ON wake_up');
        $this->addSql('ALTER TABLE wake_up RENAME INDEX uniq_f0bc394baa9e377a TO UNIQ_date');
    }
}
