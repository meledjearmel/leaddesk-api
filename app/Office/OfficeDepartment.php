<?php

namespace App\Office;

/**
 * Département vu par l'aménagement : une unité de premier niveau, son nom et
 * son effectif (postes occupés ou vacants de l'unité et de ses sous-unités).
 */
final readonly class OfficeDepartment
{
    public function __construct(
        public string $id,
        public string $name,
        public int $headcount,
    ) {}
}
