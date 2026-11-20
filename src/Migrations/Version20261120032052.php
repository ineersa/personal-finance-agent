<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120032052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_metadata ADD COLUMN handoff CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_metadata AS SELECT id, label, favourite, model, effort, charts, user_id FROM chat_metadata');
        $this->addSql('DROP TABLE chat_metadata');
        $this->addSql('CREATE TABLE chat_metadata (id VARCHAR(36) NOT NULL, label CLOB NOT NULL, favourite BOOLEAN NOT NULL, model VARCHAR(255) DEFAULT NULL, effort VARCHAR(255) DEFAULT NULL, charts CLOB NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7B36E324A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_metadata (id, label, favourite, model, effort, charts, user_id) SELECT id, label, favourite, model, effort, charts, user_id FROM __temp__chat_metadata');
        $this->addSql('DROP TABLE __temp__chat_metadata');
        $this->addSql('CREATE INDEX IDX_7B36E324A76ED395 ON chat_metadata (user_id)');
    }
}
