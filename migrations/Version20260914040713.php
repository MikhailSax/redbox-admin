<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914040713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment calendar; client type and requisites; the client of a media plan';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, amount NUMERIC(12, 2) NOT NULL, due_date DATE NOT NULL, paid_at DATETIME DEFAULT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, client_id INT NOT NULL, media_plan_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_payment_due (due_date, paid_at), INDEX IDX_6D28840D19EB6921 (client_id), INDEX IDX_6D28840D894E84D2 (media_plan_id), INDEX IDX_6D28840DB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D19EB6921 FOREIGN KEY (client_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D894E84D2 FOREIGN KEY (media_plan_id) REFERENCES media_plan (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media_plan ADD client_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_plan ADD CONSTRAINT FK_1E1D84AE19EB6921 FOREIGN KEY (client_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1E1D84AE19EB6921 ON media_plan (client_id)');
        $this->addSql('ALTER TABLE user ADD client_type VARCHAR(20) DEFAULT NULL, ADD inn VARCHAR(12) DEFAULT NULL, ADD kpp VARCHAR(9) DEFAULT NULL, ADD ogrn VARCHAR(15) DEFAULT NULL, ADD legal_address VARCHAR(255) DEFAULT NULL');
        // existing clients: those with an organisation become companies (the manager adds the ИНН), the rest private persons
        $this->addSql("UPDATE user SET client_type = IF(company IS NULL, 'individual', 'legal') WHERE roles LIKE '%ROLE_CLIENT%'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D19EB6921');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D894E84D2');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DB03A8386');
        $this->addSql('DROP TABLE payment');
        $this->addSql('ALTER TABLE media_plan DROP FOREIGN KEY FK_1E1D84AE19EB6921');
        $this->addSql('DROP INDEX IDX_1E1D84AE19EB6921 ON media_plan');
        $this->addSql('ALTER TABLE media_plan DROP client_id');
        $this->addSql('ALTER TABLE user DROP client_type, DROP inn, DROP kpp, DROP ogrn, DROP legal_address');
    }
}
