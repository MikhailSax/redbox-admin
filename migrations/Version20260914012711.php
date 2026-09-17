<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914012711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lead_item (id INT AUTO_INCREMENT NOT NULL, product_title VARCHAR(255) NOT NULL, side_name VARCHAR(50) DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, clip_duration INT DEFAULT NULL, monthly_price NUMERIC(12, 2) DEFAULT NULL, lead_id INT NOT NULL, side_id INT DEFAULT NULL, INDEX IDX_FEED60C455458D (lead_id), INDEX IDX_FEED60C4965D81C4 (side_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE leads (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, contact_name VARCHAR(255) NOT NULL, phone VARCHAR(50) NOT NULL, email VARCHAR(180) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, inn VARCHAR(20) DEFAULT NULL, kpp VARCHAR(20) DEFAULT NULL, payment_type VARCHAR(20) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, manager_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, client_id INT DEFAULT NULL, assignee_id INT DEFAULT NULL, media_plan_id INT DEFAULT NULL, INDEX idx_lead_status_created (status, created_at), INDEX IDX_1790455219EB6921 (client_id), INDEX IDX_1790455259EC7D60 (assignee_id), INDEX IDX_17904552894E84D2 (media_plan_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lead_item ADD CONSTRAINT FK_FEED60C455458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lead_item ADD CONSTRAINT FK_FEED60C4965D81C4 FOREIGN KEY (side_id) REFERENCES product_side (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_1790455219EB6921 FOREIGN KEY (client_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_1790455259EC7D60 FOREIGN KEY (assignee_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552894E84D2 FOREIGN KEY (media_plan_id) REFERENCES media_plan (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lead_item DROP FOREIGN KEY FK_FEED60C455458D');
        $this->addSql('ALTER TABLE lead_item DROP FOREIGN KEY FK_FEED60C4965D81C4');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_1790455219EB6921');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_1790455259EC7D60');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_17904552894E84D2');
        $this->addSql('DROP TABLE lead_item');
        $this->addSql('DROP TABLE leads');
    }
}
