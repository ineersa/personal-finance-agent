<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030183258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the six-table finance schema with textual money and user relationships.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, icon VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE TABLE currency_rate (day DATE NOT NULL, currency VARCHAR(3) NOT NULL, units_per_eur CLOB NOT NULL, PRIMARY KEY (day, currency))');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, user_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_3000FF4DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3000FF4DA76ED395 ON financial_transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_5F8A7F73A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_5F8A7F73A76ED395 ON source (user_id)');
        $this->addSql('CREATE TABLE statement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, media_type VARCHAR(100) NOT NULL, byte_size INTEGER NOT NULL, sha256 VARCHAR(64) NOT NULL, uploaded_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, statement_date DATE DEFAULT NULL, user_id INTEGER NOT NULL, source_id INTEGER NOT NULL, CONSTRAINT FK_C0DB5176A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C0DB5176953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C0DB5176111795A5 ON statement (storage_key)');
        $this->addSql('CREATE INDEX IDX_C0DB5176A76ED395 ON statement (user_id)');
        $this->addSql('CREATE INDEX IDX_C0DB5176953C1C61 ON statement (source_id)');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, reporting_currency VARCHAR(3) DEFAULT \'USD\' NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('DROP TABLE statement');
        $this->addSql('DROP TABLE source');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE currency_rate');
        $this->addSql('DROP TABLE user');
    }
}
