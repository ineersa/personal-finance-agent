<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102154502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pending_statement_file_cleanup (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, filename VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_95010A213C0BE965 ON pending_statement_file_cleanup (filename)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__financial_transaction AS SELECT id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id, meaning, recurrence FROM financial_transaction');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, user_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, meaning VARCHAR(255) DEFAULT \'unknown\' NOT NULL, recurrence VARCHAR(255) DEFAULT \'unknown\' NOT NULL, CONSTRAINT FK_3000FF4DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id, meaning, recurrence) SELECT id, transaction_date, amount, currency, direction, description, is_draft, user_id, statement_id, source_id, category_id, meaning, recurrence FROM __temp__financial_transaction');
        $this->addSql('DROP TABLE __temp__financial_transaction');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4DA76ED395 ON financial_transaction (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE pending_statement_file_cleanup');
        $this->addSql('CREATE TEMPORARY TABLE __temp__financial_transaction AS SELECT id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, user_id, statement_id, source_id, category_id FROM financial_transaction');
        $this->addSql('DROP TABLE financial_transaction');
        $this->addSql('CREATE TABLE financial_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, transaction_date DATE DEFAULT NULL, amount CLOB DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, direction VARCHAR(255) DEFAULT NULL, description CLOB NOT NULL, is_draft BOOLEAN NOT NULL, meaning VARCHAR(255) DEFAULT \'unknown\' NOT NULL, recurrence VARCHAR(255) DEFAULT \'unknown\' NOT NULL, user_id INTEGER NOT NULL, statement_id INTEGER NOT NULL, source_id INTEGER NOT NULL, category_id INTEGER DEFAULT NULL, CONSTRAINT FK_3000FF4DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D953C1C61 FOREIGN KEY (source_id) REFERENCES source (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3000FF4D849CB65B FOREIGN KEY (statement_id) REFERENCES statement (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO financial_transaction (id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, user_id, statement_id, source_id, category_id) SELECT id, transaction_date, amount, currency, direction, description, is_draft, meaning, recurrence, user_id, statement_id, source_id, category_id FROM __temp__financial_transaction');
        $this->addSql('DROP TABLE __temp__financial_transaction');
        $this->addSql('CREATE INDEX IDX_3000FF4DA76ED395 ON financial_transaction (user_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D849CB65B ON financial_transaction (statement_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D953C1C61 ON financial_transaction (source_id)');
        $this->addSql('CREATE INDEX IDX_3000FF4D12469DE2 ON financial_transaction (category_id)');
    }
}
