<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103154248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chat_conversation (id VARCHAR(36) NOT NULL, generation_id VARCHAR(36) DEFAULT NULL, model VARCHAR(100) NOT NULL, reasoning_effort VARCHAR(20) NOT NULL, history CLOB NOT NULL, turns CLOB NOT NULL, status VARCHAR(255) NOT NULL, error CLOB DEFAULT NULL, account_identity VARCHAR(36) NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_74654F68125B0B72 ON chat_conversation (account_identity)');
        $this->addSql('CREATE INDEX IDX_74654F68A76ED395 ON chat_conversation (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE chat_conversation');
    }
}
