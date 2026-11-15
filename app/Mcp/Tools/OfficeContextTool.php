<?php

namespace App\Mcp\Tools;

use App\Actions\Office\ManageOfficePlans;
use App\Enums\OfficePlanStatus;
use App\Mcp\Concerns\ResolvesCompany;
use App\Office\ActivityTags;
use App\Office\CompanyOfficeDepartments;
use App\Office\OfficeDepartment;
use App\Office\OfficePlanValidator;
use App\Office\RoomCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('office.context')]
#[Description(<<<'TEXT'
Tout ce qu'il faut pour proposer un aménagement du bureau : départements (unités de premier niveau, avec effectif, couleur et activités), étiquettes d'activité, catalogue des types de salle, plan en vigueur et propositions en attente, règles de validation et format attendu par office.propose_plan.
TEXT)]
#[IsReadOnly]
class OfficeContextTool extends Tool
{
    use ResolvesCompany;

    public function handle(
        Request $request,
        CompanyOfficeDepartments $departments,
        RoomCatalog $catalog,
        ManageOfficePlans $plans,
    ): Response {
        $company = $this->company($request);
        $current = $plans->current($company);

        return Response::json([
            'departments' => array_map(fn (OfficeDepartment $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'headcount' => $department->headcount,
                'color' => $department->color,
                'tags' => $department->tags,
            ], $departments->for($company)),
            'activity_tags' => ActivityTags::all(),
            'room_types' => $catalog->all(),
            'current_plan' => $current === null ? null : [
                'version' => $current->version,
                'layout' => $current->layout,
            ],
            'pending_proposals' => $company->officePlans()->where('status', OfficePlanStatus::Proposed)->count(),
            'rules' => [
                'Deux départements ne partagent jamais une salle : les salles ne se chevauchent pas.',
                'Une salle de type « department » appartient à un département (champ department = id du département) ; un lieu commun (« shared ») n\'en a pas.',
                'Chaque salle tient dans le plan et respecte la taille minimale de son type (min_cols × min_rows).',
                'Chaque département a au moins autant de postes que son effectif. Postes d\'une salle : seats_per_row × ⌊rows / 2⌋.',
                'Garder l\'identifiant des salles qui restent : le CEO voit ainsi ce qui est déplacé, ajouté ou retiré.',
                'Placer les départements qui partagent des activités côte à côte, et ceux qui portent l\'activité « calme » loin de l\'accueil.',
            ],
            'layout_format' => [
                'cols' => 'largeur du plan en cases (1 à '.OfficePlanValidator::MAX_SIDE.')',
                'rows' => 'hauteur du plan en cases',
                'rooms' => 'liste de {id, type, department?, name?, x, y, cols, rows} ; x et y depuis le coin haut gauche',
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
