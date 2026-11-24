<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124172600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create key-value storage and copy current stat values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE key_value (id INT AUTO_INCREMENT NOT NULL, key_name VARCHAR(255) NOT NULL, value JSON DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO key_value (id, key_name, value) SELECT id, type, value FROM current_stat_value');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE key_value');
    }
}
