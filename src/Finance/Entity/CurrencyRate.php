<?php

declare(strict_types=1);

namespace App\Finance\Entity;

use App\Enum\ReportingCurrency;
use App\Repository\CurrencyRateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CurrencyRateRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_currency_rate_day_currency', columns: ['day', 'currency'])]
#[UniqueEntity(fields: ['day', 'currency'])]
class CurrencyRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['comment' => 'Primary key within this tenant database.'])]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'date_immutable', options: ['comment' => 'Calendar date used for transaction-date ECB valuation.'])]
        #[Assert\NotNull]
        public \DateTimeImmutable $day,
        #[ORM\Column(length: 3, options: ['comment' => 'ECB quoted currency code; EUR is implicit and not stored as a rate row.'])]
        #[Assert\Choice(callback: [self::class, 'currencyChoices'])]
        public string $currency,
        #[ORM\Column(type: 'exact_decimal', options: ['comment' => 'Positive canonical decimal text: units of this currency per EUR.'])]
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A(?=[0-9.]*[1-9])[0-9]+(?:\.[0-9]+)?\z/D', message: 'Enter a positive plain decimal rate.')]
        public string $rate,
        #[ORM\Column(options: ['comment' => 'Whether this stored rate is provisional.', 'default' => false])]
        public bool $isProvisional = false,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return array<string, string> */
    public static function currencyChoices(): array
    {
        $choices = [];
        foreach (ReportingCurrency::cases() as $currency) {
            if (ReportingCurrency::EUR !== $currency) {
                $choices[$currency->value] = $currency->value;
            }
        }

        return $choices;
    }
}
