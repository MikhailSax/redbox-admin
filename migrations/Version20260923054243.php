<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Video screens are sold by slots instead of seconds of a 120 s loop; sides get two-week, 3- and 6-month prices
 * and a print price; media plan items get their own texts for the PDF.
 */
final class Version20260923054243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Slots for video screens, price tiers and print price of sides, media plan item texts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking CHANGE clip_duration slots INT DEFAULT NULL');
        $this->addSql('ALTER TABLE lead_item CHANGE clip_duration slots INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_plan_item ADD title VARCHAR(255) DEFAULT NULL, ADD format VARCHAR(255) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL, CHANGE clip_duration slots INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_side ADD price2_weeks NUMERIC(12, 2) DEFAULT NULL, ADD price3_months NUMERIC(12, 2) DEFAULT NULL, ADD price6_months NUMERIC(12, 2) DEFAULT NULL, ADD print_price NUMERIC(12, 2) DEFAULT NULL, ADD print_note VARCHAR(100) DEFAULT NULL, ADD slot_seconds INT DEFAULT 5 NOT NULL, ADD slot_count INT DEFAULT 12 NOT NULL');

        // Clip seconds become 5 s slots: a 15 s clip takes 3 slots
        foreach (['booking', 'lead_item', 'media_plan_item'] as $table) {
            $this->addSql(\sprintf('UPDATE %s SET slots = CEIL(slots / 5) WHERE slots IS NOT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['booking', 'lead_item', 'media_plan_item'] as $table) {
            $this->addSql(\sprintf('UPDATE %s SET slots = slots * 5 WHERE slots IS NOT NULL', $table));
        }
        $this->addSql('ALTER TABLE booking CHANGE slots clip_duration INT DEFAULT NULL');
        $this->addSql('ALTER TABLE lead_item CHANGE slots clip_duration INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_plan_item DROP title, DROP format, DROP description, CHANGE slots clip_duration INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product_side DROP price2_weeks, DROP price3_months, DROP price6_months, DROP print_price, DROP print_note, DROP slot_seconds, DROP slot_count');
    }
}
