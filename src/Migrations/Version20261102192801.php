<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102192801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__financial_transaction AS SELECT id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id FROM financial_transaction');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, user_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, meaning VARCHAR(255) DEFAULT \'unknown\' NOT NULL, recurrence VARCHAR(255) DEFAULT \'unknown\' NOT NULL, CONSTRAINT FK_3000FF4DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id) SELECT id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id FROM __temp__financial_transaction');
        $this->addSql('DROP TABLE __temp__financial_transaction');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4DA76ED395 ON financial_transaction (user_id)');
        $this->addSql('ALTER TABLE source ADD COLUMN currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD COLUMN instrument_kind VARCHAR(255) DEFAULT \'other\' NOT NULL');
        $this->addSql('ALTER TABLE statement ADD COLUMN processing_status VARCHAR(255) DEFAULT \'completed\' NOT NULL');
        $this->addSql('ALTER TABLE statement ADD COLUMN processing_error CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN money_locale VARCHAR(255) DEFAULT \'en_US\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__financial_transaction AS SELECT id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id FROM financial_transaction');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, user_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_3000FF4DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id) SELECT id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id FROM __temp__financial_transaction');
        $this->addSql('DROP TABLE __temp__financial_transaction');
        $this->addSql('CREATE INDEX IDX_3000FF4DA76ED395 ON financial_transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__source AS SELECT id, name, description, created_at, updated_at, user_id FROM source');
        $this->addSql('DROP TABLE source');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_5F8A7F73A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO source (id, name, description, created_at, updated_at, user_id) SELECT id, name, description, created_at, updated_at, user_id FROM __temp__source');
        $this->addSql('DROP TABLE __temp__source');
        $this->addSql('CREATE INDEX IDX_5F8A7F73A76ED395 ON source (user_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__statement AS SELECT id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, user_id, source_id FROM statement');
        $this->addSql('DROP TABLE statement');
        $this->addSql('CREATE TABLE statement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, stored_filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, media_type VARCHAR(100) NOT NULL, byte_size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, uploaded_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, statement_date DATE DEFAULT NULL, user_id INTEGER NOT NULL, source_id INTEGER NOT NULL, CONSTRAINT FK_C0DB5176A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C0DB5176953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO statement (id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, user_id, source_id) SELECT id, stored_filename, original_filename, media_type, byte_size, sha256, uploaded_at, processed_at, statement_date, user_id, source_id FROM __temp__statement');
        $this->addSql('DROP TABLE __temp__statement');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STATEMENT_STORED_FILENAME ON statement (stored_filename)');
        $this->addSql('CREATE INDEX IDX_C0DB5176A76ED395 ON statement (user_id)');
        $this->addSql('CREATE INDEX IDX_C0DB5176953C1C61 ON statement (source_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, email, roles, password, reporting_currency FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, reporting_currency VARCHAR(3) DEFAULT \'USD\' NOT NULL)');
        $this->addSql('INSERT INTO user (id, email, roles, password, reporting_currency) SELECT id, email, roles, password, reporting_currency FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
    }
}
