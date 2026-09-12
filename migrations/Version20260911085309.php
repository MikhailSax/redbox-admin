<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911085309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings by days: start_month/end_month become start_date/end_date (inclusive last day)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_booking_side_period ON booking');
        // Existing bookings are whole months: the period now ends on the last day of the last month
        $this->addSql('ALTER TABLE booking RENAME COLUMN start_month TO start_date, RENAME COLUMN end_month TO end_date');
        $this->addSql('UPDATE booking SET end_date = LAST_DAY(end_date)');
        $this->addSql('CREATE INDEX idx_booking_side_period ON booking (side_id, start_date, end_date)');
    }

    public function down(Schema $schema): void
    {
        // Day bookings are widened to the months they touch
        $this->addSql('DROP INDEX idx_booking_side_period ON booking');
        $this->addSql("UPDATE booking SET start_date = DATE_FORMAT(start_date, '%Y-%m-01'), end_date = DATE_FORMAT(end_date, '%Y-%m-01')");
        $this->addSql('ALTER TABLE booking RENAME COLUMN start_date TO start_month, RENAME COLUMN end_date TO end_month');
        $this->addSql('CREATE INDEX idx_booking_side_period ON booking (side_id, start_month, end_month)');
    }
}
