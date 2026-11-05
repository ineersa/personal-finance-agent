<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Owner-approved SQLite FTS5 exception: Doctrine cannot generate virtual tables. */
final class Version20261105184401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index readable chat questions and answers with native SQLite FTS5';
    }

    public function up(Schema $schema): void
    {
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

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER chat_search_delete');
        $this->addSql('DROP TRIGGER chat_search_update');
        $this->addSql('DROP TRIGGER chat_search_insert');
        $this->addSql('DROP TABLE chat_search');
    }
}
