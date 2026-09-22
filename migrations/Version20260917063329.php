<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917063329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The client a booking is made for: a structure is taken by a client card, not by a name typed in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking ADD client_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE19EB6921 FOREIGN KEY (client_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_booking_client ON booking (client_id)');

        // Old bookings only have the typed-in name: tie the ones that match a client card exactly,
        // the rest keep the name and are shown without a link.
        $this->addSql(<<<'SQL'
            UPDATE booking b
            JOIN user u ON u.roles LIKE '%ROLE_CLIENT%' AND (u.company = b.client_name OR u.name = b.client_name)
            SET b.client_id = u.id
            WHERE b.client_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE19EB6921');
        $this->addSql('DROP INDEX idx_booking_client ON booking');
        $this->addSql('ALTER TABLE booking DROP client_id');
    }
}
