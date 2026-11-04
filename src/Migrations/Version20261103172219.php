<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103172219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD COLUMN chat_gpt_registration_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD COLUMN chat_gpt_host_id VARCHAR(45) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, chat_gpt_identity, email, roles, password, reporting_currency, money_locale FROM user');
        $this->addSql('DROP TABLE user');
        $this->addSql('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, chat_gpt_identity VARCHAR(36) DEFAULT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, reporting_currency VARCHAR(3) DEFAULT \'USD\' NOT NULL, money_locale VARCHAR(255) DEFAULT \'en_US\' NOT NULL)');
        $this->addSql('INSERT INTO user (id, chat_gpt_identity, email, roles, password, reporting_currency, money_locale) SELECT id, chat_gpt_identity, email, roles, password, reporting_currency, money_locale FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
    }
}
