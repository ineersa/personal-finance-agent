<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030183258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create owned finance, draft review, conversation and durable accounting foundations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE approved_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE NOT NULL, amount CLOB NOT NULL, currency VARCHAR(3) NOT NULL, direction VARCHAR(255) NOT NULL, description CLOB NOT NULL, owner_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_46FEFF337E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_46FEFF33849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_46FEFF33953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_46FEFF3312469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_46FEFF337E3C61F9 ON approved_transaction (owner_id)');
        $this->addSql('CREATE INDEX IDX_46FEFF33849CB65B ON approved_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_46FEFF33953C1C61 ON approved_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_46FEFF3312469DE2 ON approved_transaction (category_id)');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_64C19C17E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_64C19C17E3C61F9 ON category (owner_id)');
        $this->addSql('CREATE TABLE conversation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, saved BOOLEAN NOT NULL, created_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_8A8E26E97E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8A8E26E97E3C61F9 ON conversation (owner_id)');
        $this->addSql('CREATE TABLE conversation_message (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, role VARCHAR(32) NOT NULL, content CLOB NOT NULL, created_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, conversation_id INTEGER NOT NULL, CONSTRAINT FK_2DEB3E757E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2DEB3E759AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2DEB3E757E3C61F9 ON conversation_message (owner_id)');
        $this->addSql('CREATE INDEX IDX_2DEB3E759AC0396 ON conversation_message (conversation_id)');
        $this->addSql('CREATE TABLE daily_ai_budget (day DATE NOT NULL, reserved_tokens INTEGER NOT NULL, consumed_tokens INTEGER NOT NULL, reserved_cost CLOB NOT NULL, consumed_cost CLOB NOT NULL, PRIMARY KEY (day))');
        $this->addSql('CREATE TABLE daily_quota (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, resource VARCHAR(40) NOT NULL, consumed INTEGER NOT NULL, reserved INTEGER NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_EC3D6E397E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_quota_owner_day_resource ON daily_quota (owner_id, day, resource)');
        $this->addSql('CREATE INDEX IDX_EC3D6E397E3C61F9 ON daily_quota (owner_id)');
        $this->addSql('CREATE TABLE draft_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, row_number INTEGER NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, owner_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_330C2AC07E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_330C2AC0849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_330C2AC012469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_draft_statement_row ON draft_transaction (statement_id, row_number)');
        $this->addSql('CREATE INDEX IDX_330C2AC07E3C61F9 ON draft_transaction (owner_id)');
        $this->addSql('CREATE INDEX IDX_330C2AC0849CB65B ON draft_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_330C2AC012469DE2 ON draft_transaction (category_id)');
        $this->addSql('CREATE TABLE exchange_rate (day DATE NOT NULL, currency VARCHAR(3) NOT NULL, units_per_eur CLOB NOT NULL, PRIMARY KEY (day, currency))');
        $this->addSql('CREATE TABLE generation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, operation_key VARCHAR(64) NOT NULL, purpose VARCHAR(32) NOT NULL, provider VARCHAR(64) NOT NULL, model VARCHAR(255) NOT NULL, state VARCHAR(32) NOT NULL, reasoning VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, conversation_id INTEGER DEFAULT NULL, statement_id INTEGER DEFAULT NULL, CONSTRAINT FK_D3266C3B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D3266C3B9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D3266C3B849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D3266C3B95B1BD95 ON generation (operation_key)');
        $this->addSql('CREATE INDEX IDX_D3266C3B7E3C61F9 ON generation (owner_id)');
        $this->addSql('CREATE INDEX IDX_D3266C3B9AC0396 ON generation (conversation_id)');
        $this->addSql('CREATE INDEX IDX_D3266C3B849CB65B ON generation (statement_id)');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, kind VARCHAR(40) NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_5F8A7F737E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5F8A7F737E3C61F9 ON source (owner_id)');
        $this->addSql('CREATE TABLE statement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, media_type VARCHAR(100) NOT NULL, byte_size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, uploaded_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, source_id INTEGER NOT NULL, CONSTRAINT FK_C0DB51767E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C0DB5176953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C0DB5176111795A5 ON statement (storage_key)');
        $this->addSql('CREATE INDEX IDX_C0DB51767E3C61F9 ON statement (owner_id)');
        $this->addSql('CREATE INDEX IDX_C0DB5176953C1C61 ON statement (source_id)');
        $this->addSql('CREATE TABLE usage_accounting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, attempt_key VARCHAR(64) NOT NULL, input_tokens INTEGER NOT NULL, output_tokens INTEGER NOT NULL, cost CLOB DEFAULT NULL, recorded_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, generation_id INTEGER NOT NULL, CONSTRAINT FK_E90F7CBF7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E90F7CBF553A6EC4 FOREIGN KEY (generation_id) REFERENCES generation (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E90F7CBF3B95EA54 ON usage_accounting (attempt_key)');
        $this->addSql('CREATE INDEX IDX_E90F7CBF7E3C61F9 ON usage_accounting (owner_id)');
        $this->addSql('CREATE INDEX IDX_E90F7CBF553A6EC4 ON usage_accounting (generation_id)');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
        $this->addSql('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reporting_currency VARCHAR(3) NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_5C844C57E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_settings_owner ON user_settings (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE usage_accounting');
        $this->addSql('DROP TABLE conversation_message');
        $this->addSql('DROP TABLE generation');
        $this->addSql('DROP TABLE draft_transaction');
        $this->addSql('DROP TABLE approved_transaction');
        $this->addSql('DROP TABLE statement');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE source');
        $this->addSql('DROP TABLE user_settings');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE daily_ai_budget');
        $this->addSql('DROP TABLE daily_quota');
        $this->addSql('DROP TABLE exchange_rate');
        $this->addSql('DROP TABLE user');
    }
}
