<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102021000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source context and independent transaction meaning and recurrence without guessing existing values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE source ADD COLUMN currency VARCHAR(3) DEFAULT NULL');
        $this->addSql("ALTER TABLE source ADD COLUMN instrument_kind VARCHAR(255) DEFAULT 'other' NOT NULL");
        $this->addSql("ALTER TABLE financial_transaction ADD COLUMN meaning VARCHAR(255) DEFAULT 'unknown' NOT NULL");
        $this->addSql("ALTER TABLE financial_transaction ADD COLUMN recurrence VARCHAR(255) DEFAULT 'unknown' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE financial_transaction DROP COLUMN recurrence');
        $this->addSql('ALTER TABLE financial_transaction DROP COLUMN meaning');
        $this->addSql('ALTER TABLE source DROP COLUMN instrument_kind');
        $this->addSql('ALTER TABLE source DROP COLUMN currency');
    }
}
