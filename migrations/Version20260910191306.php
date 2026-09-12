<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910191306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Media plans (commercial proposals) and their items';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE media_plan (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, client_name VARCHAR(255) NOT NULL, client_contact VARCHAR(255) DEFAULT NULL, start_month DATE NOT NULL, months INT NOT NULL, discount_percent INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_1E1D84AEB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media_plan_item (id INT AUTO_INCREMENT NOT NULL, clip_duration INT DEFAULT NULL, monthly_price NUMERIC(12, 2) NOT NULL, position INT NOT NULL, plan_id INT NOT NULL, side_id INT NOT NULL, booking_id INT DEFAULT NULL, UNIQUE INDEX uniq_media_plan_side (plan_id, side_id), INDEX IDX_2FAD5A62E899029B (plan_id), INDEX IDX_2FAD5A62965D81C4 (side_id), INDEX IDX_2FAD5A623301C60 (booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE media_plan ADD CONSTRAINT FK_1E1D84AEB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media_plan_item ADD CONSTRAINT FK_2FAD5A62E899029B FOREIGN KEY (plan_id) REFERENCES media_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_plan_item ADD CONSTRAINT FK_2FAD5A62965D81C4 FOREIGN KEY (side_id) REFERENCES product_side (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_plan_item ADD CONSTRAINT FK_2FAD5A623301C60 FOREIGN KEY (booking_id) REFERENCES booking (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE media_plan DROP FOREIGN KEY FK_1E1D84AEB03A8386');
        $this->addSql('ALTER TABLE media_plan_item DROP FOREIGN KEY FK_2FAD5A62E899029B');
        $this->addSql('ALTER TABLE media_plan_item DROP FOREIGN KEY FK_2FAD5A62965D81C4');
        $this->addSql('ALTER TABLE media_plan_item DROP FOREIGN KEY FK_2FAD5A623301C60');
        $this->addSql('DROP TABLE media_plan');
        $this->addSql('DROP TABLE media_plan_item');
    }
}
