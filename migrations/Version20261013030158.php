<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013030158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Video screens: hours of work of a side (empty = around the clock)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_side ADD work_starts_at TIME DEFAULT NULL, ADD work_ends_at TIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_side DROP work_starts_at, DROP work_ends_at');
    }
}
