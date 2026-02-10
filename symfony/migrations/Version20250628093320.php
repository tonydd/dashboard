<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250628093320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ingredient ADD slug VARCHAR(255) NOT NULL');
        $this->addSql('CREATE INDEX idx_ingredient_slug ON ingredient (slug)');
        $this->addSql('ALTER TABLE recipe ADD slug VARCHAR(255) NOT NULL');
        $this->addSql('CREATE INDEX idx_recipe_slug ON recipe (slug)');
        $this->addSql('ALTER TABLE tag ADD slug VARCHAR(255) NOT NULL');
        $this->addSql('CREATE INDEX idx_tag_slug ON tag (slug)');
        $this->addSql('ALTER TABLE unit ADD slug VARCHAR(64) NOT NULL');
        $this->addSql('CREATE INDEX idx_unit_slug ON unit (slug)');
        $this->addSql('CREATE INDEX idx_unit_code ON unit (code)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_tag_slug ON tag');
        $this->addSql('ALTER TABLE tag DROP slug');
        $this->addSql('DROP INDEX idx_recipe_slug ON recipe');
        $this->addSql('ALTER TABLE recipe DROP slug');
        $this->addSql('DROP INDEX idx_unit_slug ON unit');
        $this->addSql('ALTER TABLE unit DROP slug');
        $this->addSql('DROP INDEX idx_ingredient_slug ON ingredient');
        $this->addSql('ALTER TABLE ingredient DROP slug');
        $this->addSql('DROP INDEX idx_unit_code ON unit');
    }
}
