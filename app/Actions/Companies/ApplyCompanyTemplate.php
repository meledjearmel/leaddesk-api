<?php

namespace App\Actions\Companies;

use App\Actions\Agents\AssignAgentToPosition;
use App\Enums\CompanyTemplate;
use App\Enums\LifecycleStatus;
use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use RuntimeException;

/**
 * Copie une configuration de départ dans une entreprise (RM-02, RM-04).
 *
 * Le modèle est une donnée (database/templates) : une fois copié, tout
 * appartient à l'entreprise et s'adapte librement. Postes et agents sont créés
 * en brouillon, sans modèle IA ni accès.
 *
 * @phpstan-type PositionDefinition array{key: string, title: string, mission: string, responsibilities: list<string>, is_head: bool, reports_to: string|null, agent: array{name: string, bio: string}}
 * @phpstan-type UnitDefinition array{name: string, kind: string, system_role?: string, color: string, positions: list<PositionDefinition>, children: list<array<string, mixed>>}
 */
class ApplyCompanyTemplate
{
    /** @var array<string, Position> */
    private array $positions = [];

    /** @var array<string, string> */
    private array $superiors = [];

    public function __construct(private readonly AssignAgentToPosition $assign) {}

    public function handle(Company $company, CompanyTemplate $template): void
    {
        if ($template === CompanyTemplate::Blank) {
            // RM-02 : une entreprise vierge ne reçoit rien du modèle ; seules
            // les unités système lui sont ajoutées ensuite (D-013).
            return;
        }

        $this->positions = [];
        $this->superiors = [];

        $path = database_path("templates/{$template->value}.php");

        if (! is_file($path)) {
            throw new RuntimeException("Modèle d'entreprise introuvable : {$template->value}.");
        }

        /** @var array{units: list<UnitDefinition>} $definition */
        $definition = require $path;

        foreach ($definition['units'] as $index => $unit) {
            $this->createUnit($company, $unit, null, $index);
        }

        // Les liens hiérarchiques se posent une fois tous les postes créés.
        foreach ($this->superiors as $key => $superiorKey) {
            $this->positions[$key]->update(['reports_to_id' => $this->positions[$superiorKey]->id]);
        }
    }

    /**
     * @param  UnitDefinition  $definition
     */
    private function createUnit(Company $company, array $definition, ?Unit $parent, int $order): void
    {
        $unit = $company->units()->create([
            'parent_id' => $parent?->id,
            'name' => $definition['name'],
            'kind' => $definition['kind'],
            'system_role' => isset($definition['system_role']) ? SystemRole::from($definition['system_role']) : null,
            'color' => $definition['color'],
            'sort_order' => $order,
        ]);

        foreach ($definition['positions'] as $index => $positionDefinition) {
            $position = $company->positions()->create([
                'unit_id' => $unit->id,
                'title' => $positionDefinition['title'],
                'mission' => $positionDefinition['mission'],
                'responsibilities' => $positionDefinition['responsibilities'],
                'is_head' => $positionDefinition['is_head'],
                'status' => LifecycleStatus::Draft,
                'sort_order' => $index,
            ]);

            $this->positions[$positionDefinition['key']] = $position;

            if ($positionDefinition['reports_to'] !== null) {
                $this->superiors[$positionDefinition['key']] = $positionDefinition['reports_to'];
            }

            $agent = $company->agents()->create([
                'name' => $positionDefinition['agent']['name'],
                'bio' => $positionDefinition['agent']['bio'],
                'status' => LifecycleStatus::Draft,
            ]);

            $this->assign->handle($agent, $position);
        }

        /** @var list<UnitDefinition> $children */
        $children = $definition['children'];

        foreach ($children as $index => $child) {
            $this->createUnit($company, $child, $unit, $index);
        }
    }
}
