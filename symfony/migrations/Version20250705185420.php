<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250705185420 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("ALTER TABLE ingredient CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $this->addSql('ALTER TABLE ingredient ADD emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient DROP emoji');
        $this->addSql("ALTER TABLE ingredient CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci;");
    }
}
