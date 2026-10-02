<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002061558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Structures: working or out of order, and why';
    }

    public function up(Schema $schema): void
    {
        // every structure works until marked otherwise
        $this->addSql('ALTER TABLE product ADD working TINYINT DEFAULT 1 NOT NULL, ADD not_working_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP working, DROP not_working_reason');
    }
}
