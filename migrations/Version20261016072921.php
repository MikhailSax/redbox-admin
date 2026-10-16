<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016072921 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payments: the booking a payment is for (the payment schedule of a booking)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD booking_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D3301C60 FOREIGN KEY (booking_id) REFERENCES booking (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6D28840D3301C60 ON payment (booking_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D3301C60');
        $this->addSql('DROP INDEX IDX_6D28840D3301C60 ON payment');
        $this->addSql('ALTER TABLE payment DROP booking_id');
    }
}
