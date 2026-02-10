<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250625195747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ingredient (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, picture VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recipe (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, duration INT DEFAULT NULL, preparation_duration INT DEFAULT NULL, cooking_duration INT DEFAULT NULL, difficulty INT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recipe_ingredient (id INT AUTO_INCREMENT NOT NULL, recipe_id INT DEFAULT NULL, unit_id INT DEFAULT NULL, ingredient_id INT NOT NULL, quantity INT NOT NULL, INDEX IDX_22D1FE1359D8A214 (recipe_id), INDEX IDX_22D1FE13F8BD700D (unit_id), INDEX IDX_22D1FE13933FE08C (ingredient_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recipe_step (id INT AUTO_INCREMENT NOT NULL, recipe_id INT NOT NULL, description LONGTEXT NOT NULL, picture VARCHAR(255) DEFAULT NULL, position INT NOT NULL, preparation_duration INT DEFAULT NULL, cooking_duration INT DEFAULT NULL, INDEX IDX_3CA2A4E359D8A214 (recipe_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recipe_step_ingredient (id INT AUTO_INCREMENT NOT NULL, recipe_step_id INT NOT NULL, ingredient_id INT NOT NULL, INDEX IDX_EB00B5D3F5610DC (recipe_step_id), INDEX IDX_EB00B5D933FE08C (ingredient_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recipe_tag (id INT AUTO_INCREMENT NOT NULL, recipe_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_72DED3CF59D8A214 (recipe_id), INDEX IDX_72DED3CFBAD26311 (tag_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE unit (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, code VARCHAR(32) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE1359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id)');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE13F8BD700D FOREIGN KEY (unit_id) REFERENCES unit (id)');
        $this->addSql('ALTER TABLE recipe_ingredient ADD CONSTRAINT FK_22D1FE13933FE08C FOREIGN KEY (ingredient_id) REFERENCES ingredient (id)');
        $this->addSql('ALTER TABLE recipe_step ADD CONSTRAINT FK_3CA2A4E359D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id)');
        $this->addSql('ALTER TABLE recipe_step_ingredient ADD CONSTRAINT FK_EB00B5D3F5610DC FOREIGN KEY (recipe_step_id) REFERENCES recipe_step (id)');
        $this->addSql('ALTER TABLE recipe_step_ingredient ADD CONSTRAINT FK_EB00B5D933FE08C FOREIGN KEY (ingredient_id) REFERENCES recipe_ingredient (id)');
        $this->addSql('ALTER TABLE recipe_tag ADD CONSTRAINT FK_72DED3CF59D8A214 FOREIGN KEY (recipe_id) REFERENCES recipe (id)');
        $this->addSql('ALTER TABLE recipe_tag ADD CONSTRAINT FK_72DED3CFBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE1359D8A214');
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE13F8BD700D');
        $this->addSql('ALTER TABLE recipe_ingredient DROP FOREIGN KEY FK_22D1FE13933FE08C');
        $this->addSql('ALTER TABLE recipe_step DROP FOREIGN KEY FK_3CA2A4E359D8A214');
        $this->addSql('ALTER TABLE recipe_step_ingredient DROP FOREIGN KEY FK_EB00B5D3F5610DC');
        $this->addSql('ALTER TABLE recipe_step_ingredient DROP FOREIGN KEY FK_EB00B5D933FE08C');
        $this->addSql('ALTER TABLE recipe_tag DROP FOREIGN KEY FK_72DED3CF59D8A214');
        $this->addSql('ALTER TABLE recipe_tag DROP FOREIGN KEY FK_72DED3CFBAD26311');
        $this->addSql('DROP TABLE ingredient');
        $this->addSql('DROP TABLE recipe');
        $this->addSql('DROP TABLE recipe_ingredient');
        $this->addSql('DROP TABLE recipe_step');
        $this->addSql('DROP TABLE recipe_step_ingredient');
        $this->addSql('DROP TABLE recipe_tag');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE unit');
    }
}
