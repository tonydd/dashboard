<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the last update time of each key value for the status screen';
    }

    public function up(Schema $schema): void
    {
        // MySQL backfills existing rows with the current timestamp default.
        $this->addSql("ALTER TABLE key_value ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE key_value DROP COLUMN updated_at');
    }
}
