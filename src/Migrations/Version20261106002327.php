<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106002327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discard application chat history and use the native Symfony AI store with linked metadata';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chat_messages (id VARCHAR(36) NOT NULL, version VARCHAR(36) NOT NULL, messages CLOB NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE chat_metadata (id VARCHAR(36) NOT NULL, label CLOB NOT NULL, favourite BOOLEAN NOT NULL, charts CLOB NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_7B36E324A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7B36E324A76ED395 ON chat_metadata (user_id)');
        $this->addSql('DROP TABLE chat_conversation');
        // Owner-approved chat-only discard: no conversion, finance data or credential changes.
        // FTS-specific SQL remains the explicitly approved SQLite exception.
        $this->addSql('DROP TABLE chat_search');
        $this->addSql('DROP TABLE chat_search_document');
        $this->addSql('CREATE TABLE chat_search_document (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id VARCHAR(36) NOT NULL UNIQUE)');
        $this->addSql('CREATE VIRTUAL TABLE chat_search USING fts5(conversation_id UNINDEXED, title, body, tokenize = unicode61)');
        $text = self::searchText('new.messages');
        foreach (['INSERT', 'UPDATE OF messages'] as $event) {
            $name = 'INSERT' === $event ? 'chat_messages_search_insert' : 'chat_messages_search_update';
            $this->addSql(<<<SQL
                CREATE TRIGGER $name AFTER $event ON chat_messages BEGIN
                    DELETE FROM chat_search WHERE rowid = (SELECT id FROM chat_search_document WHERE conversation_id = new.id);
                    INSERT INTO chat_search (rowid, conversation_id, title, body)
                    SELECT d.id, c.id, c.label, $text
                    FROM chat_metadata c JOIN chat_search_document d ON d.conversation_id = c.id WHERE c.id = new.id;
                END
                SQL);
        }
        $existing = self::searchText("coalesce((SELECT messages FROM chat_messages WHERE id = new.id), '{\"messages\": []}')");
        $this->addSql(<<<SQL
            CREATE TRIGGER chat_metadata_search_insert AFTER INSERT ON chat_metadata BEGIN
                INSERT INTO chat_search_document (conversation_id) VALUES (new.id);
                INSERT INTO chat_search (rowid, conversation_id, title, body)
                VALUES ((SELECT id FROM chat_search_document WHERE conversation_id = new.id), new.id, new.label, $existing);
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_metadata_search_delete AFTER DELETE ON chat_metadata BEGIN
                DELETE FROM chat_search WHERE rowid = (SELECT id FROM chat_search_document WHERE conversation_id = old.id);
                DELETE FROM chat_search_document WHERE conversation_id = old.id;
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER chat_messages_search_delete AFTER DELETE ON chat_messages BEGIN
                DELETE FROM chat_search WHERE rowid = (SELECT id FROM chat_search_document WHERE conversation_id = old.id);
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Discarded chat history cannot be restored.');
    }

    /** Index only native user questions and assistant Text, never context, signatures or tool payloads. */
    private static function searchText(string $payload): string
    {
        $user = \Symfony\AI\Platform\Message\UserMessage::class;
        $assistant = \Symfony\AI\Platform\Message\AssistantMessage::class;
        $text = \Symfony\AI\Platform\Message\Content\Text::class;
        $commentary = \App\Chat\Workspace::COMMENTARY_PHASE;

        return <<<SQL
            coalesce((SELECT group_concat(json_extract(p.value, '$.text'), char(10))
                FROM json_each($payload, '$.messages') m, json_each(m.value, '$.parts') p
                WHERE json_extract(p.value, '$.type') = '$text'
                AND ((json_extract(m.value, '$.type') = '$user'
                    AND m.key != (SELECT min(u.key) FROM json_each($payload, '$.messages') u WHERE json_extract(u.value, '$.type') = '$user'))
                    OR (json_extract(m.value, '$.type') = '$assistant'
                        AND coalesce(json_extract(p.value, '$.phase'), '') != '$commentary'))), '')
            SQL;
    }
}
