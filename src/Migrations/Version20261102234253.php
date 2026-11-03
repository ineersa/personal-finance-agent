<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102234253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__currency_rate AS SELECT day, currency, units_per_eur FROM currency_rate');
        $this->addSql('DROP TABLE currency_rate');
        $this->addSql('CREATE TABLE currency_rate (day DATE NOT NULL, currency VARCHAR(3) NOT NULL, rate CLOB NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, is_provisional BOOLEAN DEFAULT 0 NOT NULL)');
        $this->addSql('INSERT INTO currency_rate (day, currency, rate) SELECT day, currency, units_per_eur FROM __temp__currency_rate');
        $this->addSql('DROP TABLE __temp__currency_rate');
        $this->addSql('CREATE UNIQUE INDEX uniq_currency_rate_day_currency ON currency_rate (day, currency)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__currency_rate AS SELECT day, currency, rate FROM currency_rate');
        $this->addSql('DROP TABLE currency_rate');
        $this->addSql('CREATE TABLE currency_rate (day DATE NOT NULL, currency VARCHAR(3) NOT NULL, units_per_eur CLOB NOT NULL, PRIMARY KEY (day, currency))');
        $this->addSql('INSERT INTO currency_rate (day, currency, units_per_eur) SELECT day, currency, rate FROM __temp__currency_rate');
        $this->addSql('DROP TABLE __temp__currency_rate');
    }
}
