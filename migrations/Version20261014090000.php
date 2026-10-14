<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Package offers: packages, their positions and the sides of each; the package of a request from the website';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE package (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, starts_at DATE NOT NULL, ends_at DATE DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, min_months INT DEFAULT 1 NOT NULL, max_months INT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE package_slot (id INT AUTO_INCREMENT NOT NULL, package_id INT NOT NULL, title VARCHAR(255) NOT NULL, quantity INT NOT NULL, price NUMERIC(12, 2) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, INDEX IDX_174345AFF44CABFF (package_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE package_slot_side (package_slot_id INT NOT NULL, product_side_id INT NOT NULL, INDEX IDX_57467CE9D6BE7410 (package_slot_id), INDEX IDX_57467CE947849A74 (product_side_id), PRIMARY KEY (package_slot_id, product_side_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE package_slot ADD CONSTRAINT FK_174345AFF44CABFF FOREIGN KEY (package_id) REFERENCES package (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE package_slot_side ADD CONSTRAINT FK_57467CE9D6BE7410 FOREIGN KEY (package_slot_id) REFERENCES package_slot (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE package_slot_side ADD CONSTRAINT FK_57467CE947849A74 FOREIGN KEY (product_side_id) REFERENCES product_side (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE leads ADD package_title VARCHAR(255) DEFAULT NULL, ADD package_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552F44CABFF FOREIGN KEY (package_id) REFERENCES package (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_17904552F44CABFF ON leads (package_id)');
        $this->addSql('ALTER TABLE lead_item ADD package_slot_title VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lead_item DROP package_slot_title');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_17904552F44CABFF');
        $this->addSql('DROP INDEX IDX_17904552F44CABFF ON leads');
        $this->addSql('ALTER TABLE leads DROP package_title, DROP package_id');
        $this->addSql('ALTER TABLE package_slot_side DROP FOREIGN KEY FK_57467CE9D6BE7410');
        $this->addSql('ALTER TABLE package_slot_side DROP FOREIGN KEY FK_57467CE947849A74');
        $this->addSql('ALTER TABLE package_slot DROP FOREIGN KEY FK_174345AFF44CABFF');
        $this->addSql('DROP TABLE package_slot_side');
        $this->addSql('DROP TABLE package_slot');
        $this->addSql('DROP TABLE package');
    }
}
