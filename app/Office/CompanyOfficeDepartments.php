<?php

namespace App\Office;

use App\Models\Company;
use App\Models\Unit;

/**
 * Départements d'aménagement d'une entreprise : ses unités de premier niveau,
 * dans l'ordre de l'organigramme. L'effectif compte les postes de l'unité et
 * de toutes ses sous-unités (postes occupés et vacants).
 *
 * L'identifiant d'un département dans un plan est l'identifiant de l'unité,
 * en texte.
 */
class CompanyOfficeDepartments
{
    /**
     * @return list<OfficeDepartment>
     */
    public function for(Company $company): array
    {
        $units = $company->units()->get(['id', 'parent_id', 'name', 'sort_order']);
        /** @var array<int, int> $positions */
        $positions = $company->positions()
            ->selectRaw('unit_id, count(*) as total')
            ->groupBy('unit_id')
            ->pluck('total', 'unit_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        /** @var array<int, Unit> $byId */
        $byId = $units->keyBy('id')->all();
        $headcounts = [];

        foreach ($units as $unit) {
            $root = $this->rootOf($unit, $byId);
            $headcounts[$root->id] = ($headcounts[$root->id] ?? 0) + ($positions[$unit->id] ?? 0);
        }

        $departments = [];

        foreach ($units->whereNull('parent_id')->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $unit) {
            $departments[] = new OfficeDepartment((string) $unit->id, $unit->name, $headcounts[$unit->id] ?? 0);
        }

        return $departments;
    }

    /**
     * @param  array<int, Unit>  $byId
     */
    private function rootOf(Unit $unit, array $byId): Unit
    {
        $current = $unit;
        $seen = [];

        while ($current->parent_id !== null && isset($byId[$current->parent_id]) && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $current = $byId[$current->parent_id];
        }

        return $current;
    }
}
