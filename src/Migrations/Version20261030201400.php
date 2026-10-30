<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030201400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Name personal user references consistently without changing their data.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE source RENAME COLUMN owner_id TO user_id');
        $this->addSql('ALTER TABLE statement RENAME COLUMN owner_id TO user_id');
        $this->addSql('ALTER TABLE financial_transaction RENAME COLUMN owner_id TO user_id');
        $this->addSql('DROP INDEX IDX_5F8A7F737E3C61F9');
        $this->addSql('CREATE INDEX IDX_5F8A7F73A76ED395 ON source (user_id)');
        $this->addSql('DROP INDEX IDX_C0DB51767E3C61F9');
        $this->addSql('CREATE INDEX IDX_C0DB5176A76ED395 ON statement (user_id)');
        $this->addSql('DROP INDEX IDX_3000FF4D7E3C61F9');
        $this->addSql('CREATE INDEX IDX_3000FF4DA76ED395 ON financial_transaction (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_3000FF4DA76ED395');
        $this->addSql('CREATE INDEX IDX_3000FF4D7E3C61F9 ON financial_transaction (user_id)');
        $this->addSql('DROP INDEX IDX_C0DB5176A76ED395');
        $this->addSql('CREATE INDEX IDX_C0DB51767E3C61F9 ON statement (user_id)');
        $this->addSql('DROP INDEX IDX_5F8A7F73A76ED395');
        $this->addSql('CREATE INDEX IDX_5F8A7F737E3C61F9 ON source (user_id)');
        $this->addSql('ALTER TABLE financial_transaction RENAME COLUMN user_id TO owner_id');
        $this->addSql('ALTER TABLE statement RENAME COLUMN user_id TO owner_id');
        $this->addSql('ALTER TABLE source RENAME COLUMN user_id TO owner_id');
    }
}
