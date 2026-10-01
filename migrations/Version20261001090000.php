<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings: confirmed apart from paid (post-paying clients); screens: 5 seconds of a 10-second slot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking ADD slot_seconds INT DEFAULT NULL, ADD confirmed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE media_plan_item ADD slot_seconds INT DEFAULT NULL');
        // a paid booking was the only kind of fixed one: it is confirmed and stays paid
        $this->addSql("UPDATE booking SET status = 'confirmed', confirmed_at = paid_at WHERE status = 'paid'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE booking SET status = 'paid', paid_at = COALESCE(paid_at, confirmed_at) WHERE status = 'confirmed'");
        $this->addSql('ALTER TABLE booking DROP slot_seconds, DROP confirmed_at');
        $this->addSql('ALTER TABLE media_plan_item DROP slot_seconds');
    }
}
