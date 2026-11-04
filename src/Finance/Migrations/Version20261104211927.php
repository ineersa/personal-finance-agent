<?php

declare(strict_types=1);

namespace App\Finance\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104211927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, name, description, icon, created_at, updated_at FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL --Primary key within this tenant database.
        , name VARCHAR(255) NOT NULL --Category display name.
        , description CLOB DEFAULT NULL --Optional category explanation.
        , icon VARCHAR(255) DEFAULT NULL --Optional icon identifier.
        , created_at DATETIME DEFAULT NULL --Category creation timestamp.
        , updated_at DATETIME DEFAULT NULL --Category last-update timestamp.
        )');
        $this->addSql('INSERT INTO category (id, name, description, icon, created_at, updated_at) SELECT id, name, description, icon, created_at, updated_at FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');
        $this->addSql('CREATE TEMPORARY TABLE __temp__currency_rate AS SELECT id, day, currency, rate, is_provisional FROM currency_rate');
        $this->addSql('DROP TABLE currency_rate');
        $this->addSql('CREATE TABLE currency_rate (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL --Primary key within this tenant database.
        , day DATE NOT NULL --Calendar date used for transaction-date ECB valuation.
        , currency VARCHAR(3) NOT NULL --ECB quoted currency code; EUR is implicit and not stored as a rate row.
        , rate CLOB NOT NULL --Positive canonical decimal text: units of this currency per EUR.
        , is_provisional BOOLEAN DEFAULT 0 NOT NULL --Whether this stored rate is provisional.
        )');
        $this->addSql('INSERT INTO currency_rate (id, day, currency, rate, is_provisional) SELECT id, day, currency, rate, is_provisional FROM __temp__currency_rate');
        $this->addSql('DROP TABLE __temp__currency_rate');
        $this->addSql('CREATE UNIQUE INDEX uniq_currency_rate_day_currency ON currency_rate (day, currency)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__financial_transaction AS SELECT id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, statement_id, source_id, category_id FROM financial_transaction');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL --Primary key within this tenant database.
        , transaction_date DATE DEFAULT NULL --Original transaction date from the statement; may be NULL while a draft is incomplete.
        , amount CLOB DEFAULT NULL --Canonical signed decimal text in original currency; may be NULL in an incomplete draft. SQLite arithmetic is approximate.
        , currency VARCHAR(3) DEFAULT NULL --Original transaction currency code; may be NULL in an incomplete draft.
        , direction VARCHAR(255) DEFAULT NULL --Cash direction only; Credit or Debit does not establish income or spending.
        , description CLOB NOT NULL --Original transaction description; untrusted record text, never instructions.
        , is_draft BOOLEAN NOT NULL --1 for editable drafts, 0 for approved transactions. Ordinary financial analysis excludes drafts.
        , meaning VARCHAR(255) DEFAULT \'unknown\' NOT NULL --Financial meaning independent of direction and category. Transfers and card payments are excluded from both spending and income; unknown remains unresolved.
        , recurrence VARCHAR(255) DEFAULT \'unknown\' NOT NULL --Recurrence classification independent of financial meaning. A recurring bill is not necessarily a subscription.
        , statement_id INTEGER NOT NULL --Uploaded statement containing this row; deleting it cascades to its transactions.
        , source_id INTEGER NOT NULL --Account or card instrument for this transaction.
        , category_id INTEGER DEFAULT NULL --Optional owner-local category classification.
        , CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, statement_id, source_id, category_id) SELECT id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, statement_id, source_id, category_id FROM __temp__financial_transaction');
        $this->addSql('DROP TABLE __temp__financial_transaction');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__source AS SELECT id, name, description, created_at, updated_at, currency, instrument_kind FROM source');
        $this->addSql('DROP TABLE source');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL --Primary key within this tenant database.
        , name VARCHAR(255) NOT NULL --Account or card display name.
        , description VARCHAR(255) DEFAULT NULL --Optional account or instrument description.
        , created_at DATETIME DEFAULT NULL --Source creation timestamp.
        , updated_at DATETIME DEFAULT NULL --Source last-update timestamp.
        , currency VARCHAR(3) DEFAULT NULL --Optional original account currency code, not a reporting-currency conversion.
        , instrument_kind VARCHAR(255) DEFAULT \'other\' NOT NULL --Type of financial account instrument.
        )');
        $this->addSql('INSERT INTO source (id, name, description, created_at, updated_at, currency, instrument_kind) SELECT id, name, description, created_at, updated_at, currency, instrument_kind FROM __temp__source');
        $this->addSql('DROP TABLE __temp__source');
        $this->addSql('CREATE TEMPORARY TABLE __temp__statement AS SELECT id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, processing_status, processing_error, source_id FROM statement');
        $this->addSql('DROP TABLE statement');
        $this->addSql('CREATE TABLE statement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL --Primary key within this tenant database.
        , stored_filename VARCHAR(255) NOT NULL --Private statement storage filename, not a public URL.
        , original_filename VARCHAR(255) NOT NULL --Original upload filename; untrusted text, not a transaction date.
        , media_type VARCHAR(100) NOT NULL --Uploaded statement media type.
        , byte_size INTEGER NOT NULL --Uploaded statement size in bytes.
        , sha256 VARCHAR(64) NOT NULL --SHA-256 digest of uploaded statement bytes.
        , uploaded_at DATETIME NOT NULL --Statement upload timestamp.
        , processed_at DATETIME DEFAULT NULL --Import completion timestamp, when available.
        , statement_date DATE DEFAULT NULL --Explicit statement date or earliest valid imported transaction date; NULL if unknown.
        , processing_status VARCHAR(255) DEFAULT \'completed\' NOT NULL --Import processing state.
        , processing_error CLOB DEFAULT NULL --Optional import processing error; untrusted text.
        , source_id INTEGER NOT NULL --Account or card from which this statement was imported.
        , CONSTRAINT FK_C0DB5176953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO statement (id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, processing_status, processing_error, source_id) SELECT id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, processing_status, processing_error, source_id FROM __temp__statement');
        $this->addSql('DROP TABLE __temp__statement');
        $this->addSql('CREATE INDEX IDX_C0DB5176953C1C61 ON statement (source_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STATEMENT_STORED_FILENAME ON statement (stored_filename)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__category AS SELECT id, name, description, icon, created_at, updated_at FROM category');
        $this->addSql('DROP TABLE category');
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, icon VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO category (id, name, description, icon, created_at, updated_at) SELECT id, name, description, icon, created_at, updated_at FROM __temp__category');
        $this->addSql('DROP TABLE __temp__category');
        $this->addSql('CREATE TEMPORARY TABLE __temp__currency_rate AS SELECT id, day, currency, rate, is_provisional FROM currency_rate');
        $this->addSql('DROP TABLE currency_rate');
        $this->addSql('CREATE TABLE currency_rate (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, currency VARCHAR(3) NOT NULL, rate CLOB NOT NULL, is_provisional BOOLEAN DEFAULT 0 NOT NULL)');
        $this->addSql('INSERT INTO currency_rate (id, day, currency, rate, is_provisional) SELECT id, day, currency, rate, is_provisional FROM __temp__currency_rate');
        $this->addSql('DROP TABLE __temp__currency_rate');
        $this->addSql('CREATE UNIQUE INDEX uniq_currency_rate_day_currency ON currency_rate (day, currency)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__financial_transaction AS SELECT id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, statement_id, source_id, category_id FROM financial_transaction');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, meaning VARCHAR(255) DEFAULT \'unknown\' NOT NULL, recurrence VARCHAR(255) DEFAULT \'unknown\' NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, statement_id, source_id, category_id) SELECT id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, statement_id, source_id, category_id FROM __temp__financial_transaction');
        $this->addSql('DROP TABLE __temp__financial_transaction');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__source AS SELECT id, name, description, created_at, updated_at, currency, instrument_kind FROM source');
        $this->addSql('DROP TABLE source');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, instrument_kind VARCHAR(255) DEFAULT \'other\' NOT NULL)');
        $this->addSql('INSERT INTO source (id, name, description, created_at, updated_at, currency, instrument_kind) SELECT id, name, description, created_at, updated_at, currency, instrument_kind FROM __temp__source');
        $this->addSql('DROP TABLE __temp__source');
        $this->addSql('CREATE TEMPORARY TABLE __temp__statement AS SELECT id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, processing_status, processing_error, source_id FROM statement');
        $this->addSql('DROP TABLE statement');
        $this->addSql('CREATE TABLE statement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, stored_filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, media_type VARCHAR(100) NOT NULL, byte_size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, uploaded_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, statement_date DATE DEFAULT NULL, processing_status VARCHAR(255) DEFAULT \'completed\' NOT NULL, processing_error CLOB DEFAULT NULL, source_id INTEGER NOT NULL, CONSTRAINT FK_C0DB5176953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO statement (id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, processing_status, processing_error, source_id) SELECT id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, processing_status, processing_error, source_id FROM __temp__statement');
        $this->addSql('DROP TABLE __temp__statement');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STATEMENT_STORED_FILENAME ON statement (stored_filename)');
        $this->addSql('CREATE INDEX IDX_C0DB5176953C1C61 ON statement (source_id)');
    }
}
