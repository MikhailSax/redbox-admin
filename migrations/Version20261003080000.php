<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Working or out of order: per side rather than per structure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_side ADD working TINYINT DEFAULT 1 NOT NULL, ADD not_working_reason VARCHAR(255) DEFAULT NULL');
        // a structure marked out of order: every side of it
        $this->addSql('UPDATE product_side s JOIN product p ON p.id = s.product_id SET s.working = p.working, s.not_working_reason = p.not_working_reason WHERE p.working = 0');
        $this->addSql('ALTER TABLE product DROP working, DROP not_working_reason');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD working TINYINT DEFAULT 1 NOT NULL, ADD not_working_reason VARCHAR(255) DEFAULT NULL');
        // a structure is out of order when none of its sides works
        $this->addSql('UPDATE product p SET p.working = 0, p.not_working_reason = (SELECT MAX(s.not_working_reason) FROM product_side s WHERE s.product_id = p.id)
            WHERE EXISTS (SELECT 1 FROM product_side s WHERE s.product_id = p.id) AND NOT EXISTS (SELECT 1 FROM product_side s WHERE s.product_id = p.id AND s.working = 1)');
        $this->addSql('ALTER TABLE product_side DROP working, DROP not_working_reason');
    }
}
