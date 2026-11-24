<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124172700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the legacy current stat value table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE current_stat_value');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE current_stat_value (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, value JSON DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('INSERT INTO current_stat_value (id, type, value) SELECT id, key_name, value FROM key_value');
    }
}
