<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911094007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clients: phone and company on users, client documents, photo reports with photos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_document (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, file_name VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, client_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_F68FBAB319EB6921 (client_id), INDEX IDX_F68FBAB3A2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE photo_report (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, shot_at DATE NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, client_id INT NOT NULL, product_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_919000AC19EB6921 (client_id), INDEX IDX_919000AC4584665A (product_id), INDEX IDX_919000ACA2B28FE8 (uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE photo_report_photo (id INT AUTO_INCREMENT NOT NULL, file_name VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, position INT NOT NULL, report_id INT NOT NULL, INDEX IDX_B1C08A724BD2A4C0 (report_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE client_document ADD CONSTRAINT FK_F68FBAB319EB6921 FOREIGN KEY (client_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE client_document ADD CONSTRAINT FK_F68FBAB3A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE photo_report ADD CONSTRAINT FK_919000AC19EB6921 FOREIGN KEY (client_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE photo_report ADD CONSTRAINT FK_919000AC4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE photo_report ADD CONSTRAINT FK_919000ACA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE photo_report_photo ADD CONSTRAINT FK_B1C08A724BD2A4C0 FOREIGN KEY (report_id) REFERENCES photo_report (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD phone VARCHAR(50) DEFAULT NULL, ADD company VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_document DROP FOREIGN KEY FK_F68FBAB319EB6921');
        $this->addSql('ALTER TABLE client_document DROP FOREIGN KEY FK_F68FBAB3A2B28FE8');
        $this->addSql('ALTER TABLE photo_report DROP FOREIGN KEY FK_919000AC19EB6921');
        $this->addSql('ALTER TABLE photo_report DROP FOREIGN KEY FK_919000AC4584665A');
        $this->addSql('ALTER TABLE photo_report DROP FOREIGN KEY FK_919000ACA2B28FE8');
        $this->addSql('ALTER TABLE photo_report_photo DROP FOREIGN KEY FK_B1C08A724BD2A4C0');
        $this->addSql('DROP TABLE client_document');
        $this->addSql('DROP TABLE photo_report');
        $this->addSql('DROP TABLE photo_report_photo');
        $this->addSql('ALTER TABLE user DROP phone, DROP company');
    }
}
