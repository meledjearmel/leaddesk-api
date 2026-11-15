<?php

namespace App\Office;

/**
 * Département vu par l'aménagement : une unité de premier niveau, son nom,
 * sa couleur, son effectif (postes occupés ou vacants de l'unité et de ses
 * sous-unités) et ses étiquettes d'activité.
 */
final readonly class OfficeDepartment
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $headcount,
        public ?string $color = null,
        public array $tags = [],
    ) {}
}
