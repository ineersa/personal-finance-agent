<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030201031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reduce scaffolding to finance records, shared categories and currency rates.';
    }

    public function up(Schema $schema): void
    {
        // Keep FK enforcement enabled while rebuilding the referenced category table.
        $this->addSql('PRAGMA defer_foreign_keys = ON');
        $this->addSql("ALTER TABLE user ADD COLUMN reporting_currency VARCHAR(3) DEFAULT 'USD' NOT NULL");
        $this->addSql('UPDATE user SET reporting_currency = (SELECT reporting_currency FROM user_settings WHERE owner_id = user.id) WHERE EXISTS (SELECT 1 FROM user_settings WHERE owner_id = user.id)');
        $this->addSql('ALTER TABLE source ADD COLUMN description VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD COLUMN created_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD COLUMN updated_at DATETIME DEFAULT NULL');
        // Retain existing instrument descriptions rather than discard their kind text.
        $this->addSql('UPDATE source SET description = kind');
        $this->addSql('ALTER TABLE source DROP COLUMN kind');
        $this->addSql('ALTER TABLE statement ADD COLUMN processed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE statement ADD COLUMN statement_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE exchange_rate RENAME TO currency_rate');

        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, owner_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_3000FF4D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, owner_id, statement_id, source_id, category_id) SELECT id, transaction_date, amount, currency, direction, description, 0, owner_id, statement_id, source_id, category_id FROM approved_transaction');
        // Draft IDs were in a separate namespace; allocate new IDs after approved rows.
        $this->addSql('INSERT INTO financial_transaction (transaction_date, amount, currency, direction, description, is_draft, owner_id, statement_id, source_id, category_id) SELECT draft.transaction_date, draft.amount, draft.currency, draft.direction, draft.description, 1, draft.owner_id, draft.statement_id, statement.source_id, draft.category_id FROM draft_transaction draft INNER JOIN statement ON statement.id = draft.statement_id ORDER BY draft.id');
        $this->addSql('CREATE INDEX IDX_3000FF4D7E3C61F9 ON financial_transaction (owner_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('DROP TABLE approved_transaction');
        $this->addSql('DROP TABLE draft_transaction');

        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, name FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, icon VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO category (id, name) SELECT id, name FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');

        $this->addSql('DROP TABLE usage_accounting');
        $this->addSql('DROP TABLE conversation_message');
        $this->addSql('DROP TABLE generation');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE daily_quota');
        $this->addSql('DROP TABLE daily_ai_budget');
        $this->addSql('DROP TABLE user_settings');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Shared category ownership and removed unused scaffolding cannot be reconstructed. Restore from a backup if rollback is required.');
    }
}
