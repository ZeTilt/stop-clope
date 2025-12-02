<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241202000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cigarette, wake_up and settings tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cigarette (
            id INT AUTO_INCREMENT NOT NULL,
            smoked_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            is_retroactive TINYINT(1) NOT NULL,
            INDEX idx_smoked_at (smoked_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE wake_up (
            id INT AUTO_INCREMENT NOT NULL,
            date DATE NOT NULL,
            wake_time TIME NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_date (date),
            INDEX idx_wakeup_date (date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE settings (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(50) NOT NULL,
            value VARCHAR(255) NOT NULL,
            UNIQUE INDEX UNIQ_name (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Valeurs par défaut
        $this->addSql("INSERT INTO settings (name, value) VALUES ('pack_price', '12.00')");
        $this->addSql("INSERT INTO settings (name, value) VALUES ('cigs_per_pack', '20')");
        $this->addSql("INSERT INTO settings (name, value) VALUES ('initial_daily_cigs', '20')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cigarette');
        $this->addSql('DROP TABLE wake_up');
        $this->addSql('DROP TABLE settings');
    }
}
