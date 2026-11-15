<?php

namespace App\Mcp\Tools;

use App\Actions\Office\ManageOfficePlans;
use App\Enums\ToolMode;
use App\Mcp\Concerns\ResolvesCompany;
use App\Office\OfficePlanValidator;
use App\Support\ActingAgent;
use App\Support\ToolPermissions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('office.propose_plan')]
#[Description(<<<'TEXT'
Propose un nouvel aménagement du bureau au CEO. Le plan suit le format de office.context (layout_format) et passe par les mêmes règles que l'application : s'il ne les respecte pas, rien n'est enregistré et la liste des problèmes est renvoyée, à corriger avant de proposer à nouveau. Une proposition valable attend la décision du CEO, qui l'accepte ou la refuse ; rien ne change avant. Réservé aux postes qui ont reçu cet outil (Moyens généraux).
TEXT)]
class OfficeProposePlanTool extends Tool
{
    use ResolvesCompany;

    public const string TOOL = 'office.propose_plan';

    public function handle(
        Request $request,
        ActingAgent $actingAgent,
        ToolPermissions $permissions,
        ManageOfficePlans $plans,
    ): Response {
        $agent = $actingAgent->get();

        if ($agent === null) {
            return Response::error('Seul un agent propose un aménagement : appelez cet outil au nom d\'un agent (en-tête X-LeadDesk-Agent).');
        }
        if ($permissions->modeFor($agent->position, self::TOOL) === ToolMode::Deny) {
            return Response::error("Le poste de {$agent->name} n'a pas reçu l'outil office.propose_plan.");
        }

        $data = $request->validate([
            ...OfficePlanValidator::shapeRules(),
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        /** @var array<string, mixed> $layout */
        $layout = $data['layout'];

        try {
            $plan = $plans->propose($this->company($request), $agent, OfficePlanValidator::normalize($layout), $data['reason']);
        } catch (ValidationException $exception) {
            /** @var list<string> $problems */
            $problems = $exception->errors()['layout'] ?? [];

            return Response::error("Plan refusé, rien n'est enregistré. Problèmes :\n- ".implode("\n- ", $problems));
        }

        return Response::json([
            'proposal_id' => $plan->id,
            'version' => $plan->version,
            'status' => $plan->status->value,
            'message' => 'Proposition enregistrée : elle attend la décision du CEO.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $room = $schema->object([
            'id' => $schema->string()->description('Identifiant stable de la salle ; garder celui du plan en vigueur pour une salle qui reste.')->required(),
            'type' => $schema->string()->description('Identifiant d\'un type du catalogue (room_types).')->required(),
            'department' => $schema->string()->description('Identifiant du département propriétaire ; absent pour un lieu commun.'),
            'name' => $schema->string()->description('Nom affiché, si différent du nom du type.'),
            'x' => $schema->integer()->required(),
            'y' => $schema->integer()->required(),
            'cols' => $schema->integer()->required(),
            'rows' => $schema->integer()->required(),
        ]);

        return [
            'layout' => $schema->object([
                'cols' => $schema->integer()->description('Largeur du plan en cases.')->required(),
                'rows' => $schema->integer()->description('Hauteur du plan en cases.')->required(),
                'rooms' => $schema->array()->items($room)->required(),
            ])->description('Plan proposé.')->required(),
            'reason' => $schema->string()->description('Motif, montré au CEO : ce qui change et pourquoi (embauche, nouveau département…).')->required(),
        ];
    }
}
