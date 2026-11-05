<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261105214807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store chat labels and address FTS documents by stable integer mapping';
    }

    public function up(Schema $schema): void
    {
        // Generated column SQL; only label backfill and native FTS-specific SQL are owner exceptions.
        $this->addSql('ALTER TABLE chat_conversation ADD COLUMN label CLOB DEFAULT \'New chat\' NOT NULL');
        $this->addSql("UPDATE chat_conversation SET label = coalesce(json_extract(turns, '$[0].question'), 'New chat')");
        $this->addSql('DROP TRIGGER chat_search_insert');
        $this->addSql('DROP TRIGGER chat_search_update');
        $this->addSql('DROP TRIGGER chat_search_delete');
        $this->addSql('DROP TABLE chat_search');
        // Independent INTEGER PRIMARY KEY, never chat_conversation's rebuild-sensitive hidden rowid.
        $this->addSql('CREATE TABLE chat_search_document (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id VARCHAR(36) NOT NULL UNIQUE)');
        $this->addSql('INSERT INTO chat_search_document (conversation_id) SELECT id FROM chat_conversation');
        $this->addSql('CREATE VIRTUAL TABLE chat_search USING fts5(conversation_id UNINDEXED, title, body, tokenize = unicode61)');
        $this->addSql(<<<'SQL'
            INSERT INTO chat_search (rowid, conversation_id, title, body)
            SELECT d.id, c.id, c.label,
                coalesce((SELECT group_concat(coalesce(json_extract(value, '$.question'), '') || char(10) || coalesce(json_extract(value, '$.answer'), ''), char(10)) FROM json_each(c.turns)), '')
            FROM chat_conversation c JOIN chat_search_document d ON d.conversation_id = c.id
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_search_insert AFTER INSERT ON chat_conversation BEGIN
                INSERT INTO chat_search_document (conversation_id) VALUES (new.id);
                INSERT INTO chat_search (rowid, conversation_id, title, body)
                VALUES ((SELECT id FROM chat_search_document WHERE conversation_id = new.id), new.id, new.label,
                    coalesce((SELECT group_concat(coalesce(json_extract(value, '$.question'), '') || char(10) || coalesce(json_extract(value, '$.answer'), ''), char(10)) FROM json_each(new.turns)), ''));
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_search_update AFTER UPDATE OF label, turns, generation_status ON chat_conversation
            WHEN new.label != old.label OR json_array_length(new.turns) != json_array_length(old.turns)
                OR (new.generation_status IN ('completed', 'failed', 'cancelled') AND new.turns != old.turns)
            BEGIN
                DELETE FROM chat_search WHERE rowid = (SELECT id FROM chat_search_document WHERE conversation_id = old.id);
                INSERT INTO chat_search (rowid, conversation_id, title, body)
                VALUES ((SELECT id FROM chat_search_document WHERE conversation_id = new.id), new.id, new.label,
                    coalesce((SELECT group_concat(coalesce(json_extract(value, '$.question'), '') || char(10) || coalesce(json_extract(value, '$.answer'), ''), char(10)) FROM json_each(new.turns)), ''));
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_search_delete AFTER DELETE ON chat_conversation BEGIN
                DELETE FROM chat_search WHERE rowid = (SELECT id FROM chat_search_document WHERE conversation_id = old.id);
                DELETE FROM chat_search_document WHERE conversation_id = old.id;
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER chat_search_insert');
        $this->addSql('DROP TRIGGER chat_search_update');
        $this->addSql('DROP TRIGGER chat_search_delete');
        $this->addSql('DROP TABLE chat_search');
        $this->addSql('DROP TABLE chat_search_document');
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__chat_conversation AS SELECT id, model, reasoning_effort, saved, generation_id, attempt_id, generation_status, error, history, turns, account_identity, user_id FROM chat_conversation');
        $this->addSql('DROP TABLE chat_conversation');
        $this->addSql('CREATE TABLE chat_conversation (id VARCHAR(36) NOT NULL, model VARCHAR(100) NOT NULL, reasoning_effort VARCHAR(20) NOT NULL, saved BOOLEAN DEFAULT 0 NOT NULL, generation_id VARCHAR(36) DEFAULT NULL, attempt_id VARCHAR(36) DEFAULT NULL, generation_status VARCHAR(20) DEFAULT \'completed\' NOT NULL, error CLOB DEFAULT NULL, history CLOB NOT NULL, turns CLOB NOT NULL, account_identity VARCHAR(36) DEFAULT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO chat_conversation (id, model, reasoning_effort, saved, generation_id, attempt_id, generation_status, error, history, turns, account_identity, user_id) SELECT id, model, reasoning_effort, saved, generation_id, attempt_id, generation_status, error, history, turns, account_identity, user_id FROM __temp__chat_conversation');
        $this->addSql('DROP TABLE __temp__chat_conversation');
        $this->addSql('CREATE INDEX IDX_74654F68A76ED395 ON chat_conversation (user_id)');
        // Restore the preceding native FTS definition after the generated table rollback.
        $this->addSql('CREATE VIRTUAL TABLE chat_search USING fts5(conversation_id UNINDEXED, title, body, tokenize = unicode61)');
        $this->addSql(<<<'SQL'
            INSERT INTO chat_search (conversation_id, title, body)
            SELECT id, coalesce(json_extract(turns, '$[0].question'), ''),
                coalesce((SELECT group_concat(coalesce(json_extract(value, '$.question'), '') || char(10) || coalesce(json_extract(value, '$.answer'), ''), char(10)) FROM json_each(turns)), '')
            FROM chat_conversation
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_search_insert AFTER INSERT ON chat_conversation BEGIN
                INSERT INTO chat_search (conversation_id, title, body)
                VALUES (new.id, coalesce(json_extract(new.turns, '$[0].question'), ''),
                    coalesce((SELECT group_concat(coalesce(json_extract(value, '$.question'), '') || char(10) || coalesce(json_extract(value, '$.answer'), ''), char(10)) FROM json_each(new.turns)), ''));
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_search_update AFTER UPDATE OF turns, generation_status ON chat_conversation
            WHEN json_array_length(new.turns) != json_array_length(old.turns)
                OR (new.generation_status IN ('completed', 'failed', 'cancelled') AND new.turns != old.turns)
            BEGIN
                DELETE FROM chat_search WHERE conversation_id = old.id;
                INSERT INTO chat_search (conversation_id, title, body)
                VALUES (new.id, coalesce(json_extract(new.turns, '$[0].question'), ''),
                    coalesce((SELECT group_concat(coalesce(json_extract(value, '$.question'), '') || char(10) || coalesce(json_extract(value, '$.answer'), ''), char(10)) FROM json_each(new.turns)), ''));
            END
            SQL);
        $this->addSql('CREATE TRIGGER chat_search_delete AFTER DELETE ON chat_conversation BEGIN DELETE FROM chat_search WHERE conversation_id = old.id; END');
    }
}
