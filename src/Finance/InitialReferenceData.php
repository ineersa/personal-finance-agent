<?php

declare(strict_types=1);

namespace App\Finance;

use App\Finance\Entity\Category;
use App\Repository\CurrencyRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class InitialReferenceData
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $root)
    {
    }

    public function seed(EntityManagerInterface $em): void
    {
        $definitions = Yaml::parseFile($this->root.'/fixtures/category.yaml');
        foreach ($definitions[Category::class] as $definition) {
            $em->persist(new Category(...$definition['__construct']));
        }
        $em->flush();
        $this->seedRates($em);
    }

    public function seedRates(EntityManagerInterface $em): void
    {
        /** @var non-empty-array<string, array<string, string>> $history */
        $history = json_decode((string) file_get_contents($this->root.'/resources/ecb/reference-rates.json'), true, flags: \JSON_THROW_ON_ERROR);
        $first = new \DateTimeImmutable(array_key_first($history), new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable(array_key_last($history), new \DateTimeZone('UTC'));
        $previous = [];
        $rows = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $previous = $history[$date] ?? $previous;
            foreach ($previous as $currency => $rate) {
                $rows[] = [$date, $currency, $rate];
            }
        }
        (new CurrencyRateRepository($em))->appendRates($rows);
    }
}
