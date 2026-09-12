<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911022236 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promotions (targets, promo code, first order), promotion data on media plans and their items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE promotion (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, discount_type VARCHAR(10) NOT NULL, discount_value NUMERIC(12, 2) NOT NULL, starts_at DATE NOT NULL, ends_at DATE DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, applies_to_all TINYINT DEFAULT 0 NOT NULL, code VARCHAR(50) DEFAULT NULL, first_order_only TINYINT DEFAULT 0 NOT NULL, min_months INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_C11D7DD177153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_category (promotion_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_C018BD85139DF194 (promotion_id), INDEX IDX_C018BD8512469DE2 (category_id), PRIMARY KEY (promotion_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_product (promotion_id INT NOT NULL, product_id INT NOT NULL, INDEX IDX_8B37F297139DF194 (promotion_id), INDEX IDX_8B37F2974584665A (product_id), PRIMARY KEY (promotion_id, product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE promotion_category ADD CONSTRAINT FK_C018BD85139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_category ADD CONSTRAINT FK_C018BD8512469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_product ADD CONSTRAINT FK_8B37F297139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_product ADD CONSTRAINT FK_8B37F2974584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_plan ADD promo_code VARCHAR(50) DEFAULT NULL, ADD first_order TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE media_plan_item ADD base_price NUMERIC(12, 2) DEFAULT NULL, ADD promotion_title VARCHAR(255) DEFAULT NULL, ADD manual_price TINYINT DEFAULT 0 NOT NULL, ADD promotion_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_plan_item ADD CONSTRAINT FK_2FAD5A62139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2FAD5A62139DF194 ON media_plan_item (promotion_id)');
        // Existing items: the price they have now becomes their list price
        $this->addSql('UPDATE media_plan_item SET base_price = monthly_price WHERE base_price IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_plan_item DROP FOREIGN KEY FK_2FAD5A62139DF194');
        $this->addSql('DROP INDEX IDX_2FAD5A62139DF194 ON media_plan_item');
        $this->addSql('ALTER TABLE media_plan_item DROP base_price, DROP promotion_title, DROP manual_price, DROP promotion_id');
        $this->addSql('ALTER TABLE media_plan DROP promo_code, DROP first_order');
        $this->addSql('ALTER TABLE promotion_category DROP FOREIGN KEY FK_C018BD85139DF194');
        $this->addSql('ALTER TABLE promotion_category DROP FOREIGN KEY FK_C018BD8512469DE2');
        $this->addSql('ALTER TABLE promotion_product DROP FOREIGN KEY FK_8B37F297139DF194');
        $this->addSql('ALTER TABLE promotion_product DROP FOREIGN KEY FK_8B37F2974584665A');
        $this->addSql('DROP TABLE promotion_category');
        $this->addSql('DROP TABLE promotion_product');
        $this->addSql('DROP TABLE promotion');
    }
}
