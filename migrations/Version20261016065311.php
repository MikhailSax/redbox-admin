<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016065311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Media plans: the day of the month their payments are due (1st by default, as before)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_plan ADD payment_day INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_plan DROP payment_day');
    }
}
