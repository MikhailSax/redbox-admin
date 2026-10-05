<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings: what the booking was sold for in the end';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking ADD sold_price INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP sold_price');
    }
}
