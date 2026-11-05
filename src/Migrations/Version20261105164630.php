<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261105164630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chat_conversation ADD COLUMN attempt_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_conversation AS SELECT id, model, reasoning_effort, saved, generation_id, generation_status, error, history, turns, account_identity, user_id FROM chat_conversation');
        $this->addSql('DROP TABLE chat_conversation');
        $this->addSql('CREATE TABLE chat_conversation (id VARCHAR(36) NOT NULL, model VARCHAR(100) NOT NULL, reasoning_effort VARCHAR(20) NOT NULL, saved BOOLEAN DEFAULT 0 NOT NULL, generation_id VARCHAR(36) DEFAULT NULL, generation_status VARCHAR(20) DEFAULT \'completed\' NOT NULL, error CLOB DEFAULT NULL, history CLOB NOT NULL, turns CLOB NOT NULL, account_identity VARCHAR(36) DEFAULT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_conversation (id, model, reasoning_effort, saved, generation_id, generation_status, error, history, turns, account_identity, user_id) SELECT id, model, reasoning_effort, saved, generation_id, generation_status, error, history, turns, account_identity, user_id FROM __temp__chat_conversation');
        $this->addSql('DROP TABLE __temp__chat_conversation');
        $this->addSql('CREATE INDEX IDX_74654F68A76ED395 ON chat_conversation (user_id)');
    }
}
