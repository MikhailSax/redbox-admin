<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911015447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Additional services catalog (layouts, printing, mounting) and media plan service lines';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE additional_service (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, unit VARCHAR(20) NOT NULL, price NUMERIC(12, 2) NOT NULL, cost_price NUMERIC(12, 2) DEFAULT NULL, description LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_plan_service_line (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, unit VARCHAR(20) NOT NULL, quantity NUMERIC(10, 2) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL, unit_cost NUMERIC(12, 2) DEFAULT NULL, position INT NOT NULL, plan_id INT NOT NULL, service_id INT DEFAULT NULL, INDEX IDX_933A6CE9E899029B (plan_id), INDEX IDX_933A6CE9ED5CA9E6 (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE media_plan_service_line ADD CONSTRAINT FK_933A6CE9E899029B FOREIGN KEY (plan_id) REFERENCES media_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_plan_service_line ADD CONSTRAINT FK_933A6CE9ED5CA9E6 FOREIGN KEY (service_id) REFERENCES additional_service (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_plan_service_line DROP FOREIGN KEY FK_933A6CE9E899029B');
        $this->addSql('ALTER TABLE media_plan_service_line DROP FOREIGN KEY FK_933A6CE9ED5CA9E6');
        $this->addSql('DROP TABLE additional_service');
        $this->addSql('DROP TABLE media_plan_service_line');
    }
}
