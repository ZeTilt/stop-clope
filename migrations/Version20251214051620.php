<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214051620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user relation to WakeUp entity';
    }

    public function up(Schema $schema): void
    {
        // Add user_id column
        $this->addSql('DROP INDEX UNIQ_F0BC394BAA9E377A ON wake_up');
        $this->addSql('ALTER TABLE wake_up ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE wake_up ADD CONSTRAINT FK_F0BC394BA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_F0BC394BA76ED395 ON wake_up (user_id)');

        // Migrate existing wake_ups to the first user (if exists)
        $this->addSql('UPDATE wake_up SET user_id = (SELECT id FROM user ORDER BY id LIMIT 1) WHERE user_id IS NULL');

        // Add unique constraint
        $this->addSql('CREATE UNIQUE INDEX unique_user_date ON wake_up (user_id, date)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wake_up DROP FOREIGN KEY FK_F0BC394BA76ED395');
        $this->addSql('DROP INDEX IDX_F0BC394BA76ED395 ON wake_up');
        $this->addSql('DROP INDEX unique_user_date ON wake_up');
        $this->addSql('ALTER TABLE wake_up DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F0BC394BAA9E377A ON wake_up (date)');
    }
}
