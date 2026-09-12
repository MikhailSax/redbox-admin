<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910160501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partners (structure owners) and partner purchase price';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE partner (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, inn VARCHAR(12) DEFAULT NULL, contact_name VARCHAR(255) DEFAULT NULL, phone VARCHAR(50) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD purchase_price NUMERIC(12, 2) DEFAULT NULL, ADD owner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD7E3C61F9 FOREIGN KEY (owner_id) REFERENCES partner (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_D34A04AD7E3C61F9 ON product (owner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        // The foreign key must go before the table it points to
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD7E3C61F9');
        $this->addSql('DROP INDEX IDX_D34A04AD7E3C61F9 ON product');
        $this->addSql('ALTER TABLE product DROP purchase_price, DROP owner_id');
        $this->addSql('DROP TABLE partner');
    }
}
