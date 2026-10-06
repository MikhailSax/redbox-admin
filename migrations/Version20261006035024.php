<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006035024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings: services sold with the booking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booking_service_line (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, unit VARCHAR(20) NOT NULL, quantity NUMERIC(10, 2) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL, unit_cost NUMERIC(12, 2) DEFAULT NULL, position INT NOT NULL, booking_id INT NOT NULL, service_id INT DEFAULT NULL, INDEX IDX_FDC8E1D53301C60 (booking_id), INDEX IDX_FDC8E1D5ED5CA9E6 (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking_service_line ADD CONSTRAINT FK_FDC8E1D53301C60 FOREIGN KEY (booking_id) REFERENCES booking (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_service_line ADD CONSTRAINT FK_FDC8E1D5ED5CA9E6 FOREIGN KEY (service_id) REFERENCES additional_service (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_service_line DROP FOREIGN KEY FK_FDC8E1D53301C60');
        $this->addSql('ALTER TABLE booking_service_line DROP FOREIGN KEY FK_FDC8E1D5ED5CA9E6');
        $this->addSql('DROP TABLE booking_service_line');
    }
}
