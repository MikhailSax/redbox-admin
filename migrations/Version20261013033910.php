<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013033910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Structures: width and height in metres as numbers instead of the "6x3" size key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD width DOUBLE PRECISION DEFAULT NULL, ADD height DOUBLE PRECISION DEFAULT NULL');
        // "10.5x3.5" → 10.5 and 3.5; anything that isn't such a key is left without a size
        $this->addSql("UPDATE product SET width = CAST(SUBSTRING_INDEX(size, 'x', 1) AS DECIMAL(8,2)), height = CAST(SUBSTRING_INDEX(size, 'x', -1) AS DECIMAL(8,2)) WHERE size REGEXP '^[0-9]+(\\.[0-9]+)?x[0-9]+(\\.[0-9]+)?$'");
        $this->addSql('ALTER TABLE product DROP size');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD size VARCHAR(20) DEFAULT NULL');
        // 10.50 → "10.5", 6.00 → "6"
        $this->addSql("UPDATE product SET size = CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(width, 2, 'en_US'))), 'x', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(height, 2, 'en_US')))) WHERE width IS NOT NULL AND height IS NOT NULL");
        $this->addSql('ALTER TABLE product DROP width, DROP height');
    }
}
