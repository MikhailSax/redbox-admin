<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911034800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Structure scheme number and size (ProductHelper::SIZES), per-side price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD scheme_number VARCHAR(20) DEFAULT NULL, ADD size VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_side ADD price NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP scheme_number, DROP size');
        $this->addSql('ALTER TABLE product_side DROP price');
    }
}
