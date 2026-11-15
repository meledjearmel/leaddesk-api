<?php

namespace App\Office;

use App\Actions\Office\ManageOfficePlans;
use App\Enums\LifecycleStatus;
use App\Enums\ToolMode;
use App\Mcp\Tools\OfficeProposePlanTool;
use App\Models\Company;
use App\Models\Mission;
use App\Models\Position;
use App\Support\ToolPermissions;

/**
 * Déclenchement automatique d'un réaménagement (D-012, D-039).
 *
 * Après chaque changement d'organisation (unité ou poste créé, déplacé,
 * retiré, restauré), vérifie que le plan en vigueur tient encore : règles
 * respectées et une salle au moins pour chaque département. Sinon, confie une
 * mission « réaménager » au poste qui propose les aménagements. Une seule
 * demande ouverte à la fois : les changements rapprochés sont regroupés.
 *
 * Sans plan en vigueur, rien n'est demandé : l'application utilise son
 * aménageur automatique.
 */
class OfficeLayoutWatcher
{
    public function __construct(
        private readonly ManageOfficePlans $plans,
        private readonly CompanyOfficeDepartments $departments,
        private readonly ToolPermissions $permissions,
    ) {}

    public function check(Company $company): ?Mission
    {
        $current = $this->plans->current($company);

        if ($current === null) {
            return null;
        }

        $open = $company->missions()
            ->where('kind', Mission::KIND_OFFICE_LAYOUT)
            ->whereIn('status', ['received', 'in_progress'])
            ->exists();

        if ($open) {
            return null;
        }

        $reasons = $this->reasons($company, $current->layout);

        if ($reasons === []) {
            return null;
        }

        $position = $this->planner($company);

        if ($position === null) {
            return null;
        }

        $agent = $company->agents()
            ->where('position_id', $position->id)
            ->where('status', LifecycleStatus::Active)
            ->orderBy('id')
            ->first();

        return $company->missions()->create([
            'kind' => Mission::KIND_OFFICE_LAYOUT,
            'position_id' => $position->id,
            'agent_id' => $agent?->id,
            'status' => 'received',
            'brief' => "Réaménager le bureau : l'organisation a changé et le plan en vigueur (version {$current->version}) ne convient plus.\n- "
                .implode("\n- ", $reasons)
                ."\nConsulte office.context, puis propose un nouveau plan avec office.propose_plan.",
        ]);
    }

    /**
     * @param  array{cols: int, rows: int, rooms: list<array{id: string, type: string, department?: string|null, name?: string|null, x: int, y: int, cols: int, rows: int}>}  $layout
     * @return list<string>
     */
    private function reasons(Company $company, array $layout): array
    {
        $departments = $this->departments->for($company);
        $reasons = $this->plans->problems($company, $layout);
        $owners = array_filter(array_map(fn (array $room): ?string => $room['department'] ?? null, $layout['rooms']));

        foreach ($departments as $department) {
            if (! in_array($department->id, $owners, true)) {
                $reasons[] = "Le département « {$department->name} » n'a pas encore de salle.";
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Poste qui propose les aménagements : celui qui a reçu l'outil, de
     * préférence un poste de responsable (RM-03 : aucun nom codé en dur).
     */
    private function planner(Company $company): ?Position
    {
        return $company->positions()
            ->orderByDesc('is_head')
            ->orderBy('id')
            ->get()
            ->first(fn (Position $position): bool => $this->permissions->modeFor($position, OfficeProposePlanTool::TOOL) !== ToolMode::Deny);
    }
}
