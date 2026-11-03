<?php

declare(strict_types=1);

namespace App\Entity;

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
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'date_immutable')]
        #[Assert\NotNull]
        public \DateTimeImmutable $day,
        #[ORM\Column(length: 3)]
        #[Assert\Choice(callback: [self::class, 'currencyChoices'])]
        public string $currency,
        #[ORM\Column(type: 'exact_decimal')]
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/\A(?=[0-9.]*[1-9])[0-9]+(?:\.[0-9]+)?\z/D', message: 'Enter a positive plain decimal rate.')]
        public string $rate,
        #[ORM\Column(options: ['default' => false])]
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
