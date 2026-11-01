<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101185000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the stored statement filename column without changing filenames or file contents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE statement RENAME COLUMN storage_key TO stored_filename');
        $this->addSql('DROP INDEX UNIQ_C0DB5176111795A5');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STATEMENT_STORED_FILENAME ON statement (stored_filename)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_STATEMENT_STORED_FILENAME');
        $this->addSql('ALTER TABLE statement RENAME COLUMN stored_filename TO storage_key');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C0DB5176111795A5 ON statement (storage_key)');
    }
}
