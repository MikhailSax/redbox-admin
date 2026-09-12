<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910095159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: categories, types, districts, products, sides, side photos';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, short_description VARCHAR(255) DEFAULT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description LONGTEXT DEFAULT NULL, seo_keywords VARCHAR(255) DEFAULT NULL, image VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE district (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, short_description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, short_description VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, seo_title VARCHAR(255) DEFAULT NULL, seo_description LONGTEXT DEFAULT NULL, seo_keywords VARCHAR(255) DEFAULT NULL, price NUMERIC(12, 2) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, category_id INT NOT NULL, product_type_id INT NOT NULL, district_id INT DEFAULT NULL, INDEX IDX_D34A04AD12469DE2 (category_id), INDEX IDX_D34A04AD14959723 (product_type_id), INDEX IDX_D34A04ADB08FA272 (district_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_side (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, product_id INT NOT NULL, INDEX IDX_AE6939144584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_side_photo (id INT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, original_name VARCHAR(255) DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, side_id INT NOT NULL, INDEX IDX_3F313A1D965D81C4 (side_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_type_category (product_type_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_115E22CF14959723 (product_type_id), INDEX IDX_115E22CF12469DE2 (category_id), PRIMARY KEY (product_type_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD14959723 FOREIGN KEY (product_type_id) REFERENCES product_type (id)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADB08FA272 FOREIGN KEY (district_id) REFERENCES district (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product_side ADD CONSTRAINT FK_AE6939144584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_side_photo ADD CONSTRAINT FK_3F313A1D965D81C4 FOREIGN KEY (side_id) REFERENCES product_side (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_type_category ADD CONSTRAINT FK_115E22CF14959723 FOREIGN KEY (product_type_id) REFERENCES product_type (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_type_category ADD CONSTRAINT FK_115E22CF12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04AD14959723');
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04ADB08FA272');
        $this->addSql('ALTER TABLE product_side DROP FOREIGN KEY FK_AE6939144584665A');
        $this->addSql('ALTER TABLE product_side_photo DROP FOREIGN KEY FK_3F313A1D965D81C4');
        $this->addSql('ALTER TABLE product_type_category DROP FOREIGN KEY FK_115E22CF14959723');
        $this->addSql('ALTER TABLE product_type_category DROP FOREIGN KEY FK_115E22CF12469DE2');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE district');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_side');
        $this->addSql('DROP TABLE product_side_photo');
        $this->addSql('DROP TABLE product_type');
        $this->addSql('DROP TABLE product_type_category');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
