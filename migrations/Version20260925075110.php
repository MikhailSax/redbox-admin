<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925075110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Client cards without email or contact person (added by a manager, imported from a list)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE email email VARCHAR(180) DEFAULT NULL, CHANGE name name VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user CHANGE email email VARCHAR(180) NOT NULL, CHANGE name name VARCHAR(100) NOT NULL');
    }
}
