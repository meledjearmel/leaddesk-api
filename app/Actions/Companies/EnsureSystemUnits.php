<?php

namespace App\Actions\Companies;

use App\Enums\LifecycleStatus;
use App\Enums\SystemRole;
use App\Models\Company;

/**
 * Garantit les unités système d'une entreprise (D-013) : pour chaque rôle
 * absent, crée une unité de premier niveau avec un poste de responsable en
 * brouillon, sans agent. Idempotent.
 */
class EnsureSystemUnits
{
    public function handle(Company $company): void
    {
        foreach (SystemRole::cases() as $role) {
            if ($company->units()->where('system_role', $role)->exists()) {
                continue;
            }

            $unit = $company->units()->create([
                'name' => $role->defaultName(),
                'kind' => 'Département',
                'system_role' => $role,
                'color' => $role->defaultColor(),
                'activity_tags' => $role->defaultActivityTags(),
                'sort_order' => (int) $company->units()->whereNull('parent_id')->max('sort_order') + 1,
            ]);

            $company->positions()->create([
                'unit_id' => $unit->id,
                'title' => $role->headTitle(),
                'mission' => $role->headMission(),
                'responsibilities' => [],
                'is_head' => true,
                'tool_permissions' => $role->headToolPermissions(),
                'status' => LifecycleStatus::Draft,
                'sort_order' => 0,
            ]);
        }
    }
}
