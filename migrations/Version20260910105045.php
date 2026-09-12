<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910105045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookings; booking mode of structure types';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, start_month DATE NOT NULL, end_month DATE NOT NULL, clip_duration INT DEFAULT NULL, status VARCHAR(20) NOT NULL, expires_at DATETIME DEFAULT NULL, paid_at DATETIME DEFAULT NULL, client_name VARCHAR(255) NOT NULL, client_phone VARCHAR(50) NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, side_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_booking_side_period (side_id, start_month, end_month), INDEX idx_booking_status_expires (status, expires_at), INDEX IDX_E00CEDDE965D81C4 (side_id), INDEX IDX_E00CEDDEB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE965D81C4 FOREIGN KEY (side_id) REFERENCES product_side (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product_type ADD booking_mode VARCHAR(20) DEFAULT \'side\' NOT NULL');
        // Video screens are sold as airtime (clips in a 2-minute loop)
        $this->addSql("UPDATE product_type SET booking_mode = 'airtime' WHERE name LIKE 'Видеоэкран%'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE965D81C4');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEB03A8386');
        $this->addSql('DROP TABLE booking');
        $this->addSql('ALTER TABLE product_type DROP booking_mode');
    }
}
