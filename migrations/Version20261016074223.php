<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016074223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings: the kind (commercial, social advertising, certificate, bonus) and the sum of a certificate';
    }

    public function up(Schema $schema): void
    {
        // every booking so far was sold for money
        $this->addSql('ALTER TABLE booking ADD kind VARCHAR(20) DEFAULT \'commercial\' NOT NULL, ADD certificate_amount NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP kind, DROP certificate_amount');
    }
}
