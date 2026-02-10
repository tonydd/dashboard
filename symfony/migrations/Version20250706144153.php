<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250706144153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recipe_tag MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE recipe_tag DROP FOREIGN KEY FK_72DED3CF59D8A214');
        $this->addSql('ALTER TABLE recipe_tag DROP FOREIGN KEY FK_72DED3CFBAD26311');
        $this->addSql('DROP INDEX `primary` ON recipe_tag');
        $this->addSql('ALTER TABLE recipe_tag DROP id');
        $this->addSql('ALTER TABLE recipe_tag ADD CONSTRAINT FK_72DED3CF59D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe_tag ADD CONSTRAINT FK_72DED3CFBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe_tag ADD PRIMARY KEY (recipe_id, tag_id)');
        $this->addSql('ALTER TABLE recipe_step_ingredient MODIFY id INT NOT NULL');
        $this->addSql('ALTER TABLE recipe_step_ingredient DROP FOREIGN KEY FK_EB00B5D933FE08C');
        $this->addSql('ALTER TABLE recipe_step_ingredient DROP FOREIGN KEY FK_EB00B5D3F5610DC');
        $this->addSql('DROP INDEX IDX_EB00B5D933FE08C ON recipe_step_ingredient');
        $this->addSql('DROP INDEX `primary` ON recipe_step_ingredient');
        $this->addSql('ALTER TABLE recipe_step_ingredient DROP id, CHANGE ingredient_id recipe_ingredient_id INT NOT NULL');
        $this->addSql('ALTER TABLE recipe_step_ingredient ADD CONSTRAINT FK_EB00B5D3CAF64A FOREIGN KEY (recipe_ingredient_id) REFERENCES recipe_ingredient (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recipe_step_ingredient ADD CONSTRAINT FK_EB00B5D3F5610DC FOREIGN KEY (recipe_step_id) REFERENCES recipe_step (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_EB00B5D3CAF64A ON recipe_step_ingredient (recipe_ingredient_id)');
        $this->addSql('ALTER TABLE recipe_step_ingredient ADD PRIMARY KEY (recipe_step_id, recipe_ingredient_id)');
    }

    public function down(Schema $schema): void
    {
    }
}
