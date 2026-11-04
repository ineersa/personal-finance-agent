<?php

declare(strict_types=1);

namespace App\Finance\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104142652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, icon VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE TABLE currency_rate (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, currency VARCHAR(3) NOT NULL, rate CLOB NOT NULL, is_provisional BOOLEAN DEFAULT 0 NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_currency_rate_day_currency ON currency_rate (day, currency)');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, meaning VARCHAR(255) DEFAULT \'unknown\' NOT NULL, recurrence VARCHAR(255) DEFAULT \'unknown\' NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, instrument_kind VARCHAR(255) DEFAULT \'other\' NOT NULL)');
        $this->addSql('CREATE TABLE statement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, stored_filename VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, media_type VARCHAR(100) NOT NULL, byte_size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, uploaded_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, statement_date DATE DEFAULT NULL, processing_status VARCHAR(255) DEFAULT \'completed\' NOT NULL, processing_error CLOB DEFAULT NULL, source_id INTEGER NOT NULL, CONSTRAINT FK_C0DB5176953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_STATEMENT_STORED_FILENAME ON statement (stored_filename)');
        $this->addSql('CREATE INDEX IDX_C0DB5176953C1C61 ON statement (source_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE currency_rate');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('DROP TABLE source');
        $this->addSql('DROP TABLE statement');
    }
}
