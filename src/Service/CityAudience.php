<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/**
 * The audience of the city the structures stand in, for GRP (gross rating points): contacts per 100 residents.
 *
 * GRP of a side is its OTS a day against the population (45 000 OTS in a city of 450 000 = 10 GRP); the GRP
 * of a media plan is its contacts summed over the sides, a day on average or over the whole period.
 * Contacts are gross: one resident who passes two sides is counted twice, so GRP may be over 100.
 */
final class CityAudience
{
    public function __construct(
        #[Autowire('%app.reach.city%')] private readonly string $city,
        #[Autowire('%app.reach.population%')] private readonly int $population,
    ) {
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPopulation(): int
    {
        return $this->population;
    }

    /**
     * GRP of $contacts: over $days days on average a day, or as they are with $days = 1.
     */
    #[AsTwigFunction('grp')]
    public function grp(int|float $contacts, int $days = 1): float
    {
        return $this->population > 0 && $days > 0 ? round($contacts / $days / $this->population * 100, 2) : 0.0;
    }

    /** "Улан-Удэ, 435 067 чел.": what GRP is counted against */
    #[AsTwigFunction('grp_base')]
    public function describe(): string
    {
        return \sprintf('%s, %s чел.', $this->city, number_format($this->population, 0, ',', ' '));
    }
}
