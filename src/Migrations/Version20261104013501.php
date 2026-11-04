<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104013501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chat_conversation (id VARCHAR(36) NOT NULL, model VARCHAR(100) NOT NULL, reasoning_effort VARCHAR(20) NOT NULL, history CLOB NOT NULL, turns CLOB NOT NULL, account_identity VARCHAR(36) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_74654F68125B0B72 ON chat_conversation (account_identity)');
        $this->addSql('CREATE INDEX IDX_74654F68A76ED395 ON chat_conversation (user_id)');
        $this->addSql('ALTER TABLE user ADD COLUMN chat_gpt_registration_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE chat_conversation');
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, chat_gpt_identity, email, roles, password, reporting_currency, money_locale FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_gpt_identity VARCHAR(36) DEFAULT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, reporting_currency VARCHAR(3) DEFAULT \'USD\' NOT NULL, money_locale VARCHAR(255) DEFAULT \'en_US\' NOT NULL)');
        $this->addSql('INSERT INTO user (id, chat_gpt_identity, email, roles, password, reporting_currency, money_locale) SELECT id, chat_gpt_identity, email, roles, password, reporting_currency, money_locale FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
    }
}
