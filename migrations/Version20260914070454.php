<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914070454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Confirmed e-mail of website accounts; existing accounts were made by managers and count as confirmed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD email_verified_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE user SET email_verified_at = COALESCE(created_at, NOW())');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP email_verified_at');
    }
}
