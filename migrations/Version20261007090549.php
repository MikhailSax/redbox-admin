<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007090549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings: "Продано за" with kopecks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking CHANGE sold_price sold_price NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking CHANGE sold_price sold_price INT DEFAULT NULL');
    }
}
