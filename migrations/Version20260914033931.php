<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914033931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Own type of a structure side (a screen on one side, a static poster on another)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product_side ADD product_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_side ADD CONSTRAINT FK_AE69391414959723 FOREIGN KEY (product_type_id) REFERENCES product_type (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_AE69391414959723 ON product_side (product_type_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product_side DROP FOREIGN KEY FK_AE69391414959723');
        $this->addSql('DROP INDEX IDX_AE69391414959723 ON product_side');
        $this->addSql('ALTER TABLE product_side DROP product_type_id');
    }
}
