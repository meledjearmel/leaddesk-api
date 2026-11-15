<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Office\ManageOfficePlans;
use App\Enums\OfficePlanStatus;
use App\Http\Resources\V1\OfficePlanResource;
use App\Models\OfficePlan;
use App\Office\ActivityTags;
use App\Office\CompanyOfficeDepartments;
use App\Office\OfficeDepartment;
use App\Office\OfficePlanValidator;
use App\Office\RoomCatalog;
use App\Support\ActingAgent;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Aménagement du bureau : catalogue des salles, plan en vigueur et
 * propositions soumises au CEO (D-012, D-036).
 */
#[Group('Aménagement', weight: 9)]
class OfficePlanController extends ApiController
{
    public function __construct(
        private readonly ManageOfficePlans $plans,
        private readonly ActingAgent $actingAgent,
    ) {}

    /**
     * Catalogue des types de salle.
     *
     * `scope` vaut `department` (salle d'un seul département) ou `shared`
     * (lieu commun). Postes d'une salle : `seats_per_row × ⌊rows / 2⌋`.
     */
    public function roomTypes(RoomCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->all()]);
    }

    /**
     * Départements d'aménagement.
     *
     * Les unités de premier niveau, avec leur effectif (postes de l'unité et de
     * ses sous-unités) : c'est contre elles que tout plan est validé.
     */
    public function departments(Request $request, CompanyOfficeDepartments $departments): JsonResponse
    {
        return response()->json(['data' => array_map(fn (OfficeDepartment $department): array => [
            'id' => $department->id,
            'name' => $department->name,
            'headcount' => $department->headcount,
            /** Couleur #RRGGBB de l'unité, vide si elle n'en a pas. */
            'color' => $department->color,
            /** Étiquettes d'activité de l'unité. */
            'tags' => $department->tags,
        ], $departments->for($this->company($request)))]);
    }

    /**
     * Étiquettes d'activité reconnues.
     *
     * Une unité porte celles de ses activités (`activity_tags`) ; les types de
     * salle servent ces mêmes étiquettes.
     */
    public function activityTags(): JsonResponse
    {
        return response()->json(['data' => ActivityTags::all()]);
    }

    /**
     * Plan en vigueur.
     *
     * `data` est vide tant qu'aucun plan n'a été accepté : l'application
     * utilise alors son aménageur automatique.
     */
    public function current(Request $request): JsonResponse
    {
        $plan = $this->plans->current($this->company($request));

        return response()->json(['data' => $plan === null ? null : new OfficePlanResource($plan)]);
    }

    /**
     * Enregistrer le plan dessiné par le CEO.
     *
     * Le plan entre en vigueur sans approbation ; les propositions en attente
     * deviennent périmées. Refusé (422, `errors.layout`) si une règle n'est pas
     * respectée ; réservé au CEO (403 pour un agent).
     */
    public function save(Request $request): OfficePlanResource
    {
        $this->assertCeo();
        $data = $request->validate(OfficePlanValidator::shapeRules());

        /** @var array<string, mixed> $layout */
        $layout = $data['layout'];

        return new OfficePlanResource($this->plans->saveByCeo($this->company($request), OfficePlanValidator::normalize($layout)));
    }

    /**
     * Vérifier un plan sans l'enregistrer.
     *
     * Renvoie la liste des problèmes, vide si le plan est valable.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(OfficePlanValidator::shapeRules());

        /** @var array<string, mixed> $layout */
        $layout = $data['layout'];

        return response()->json(['data' => [
            'problems' => $this->plans->problems($this->company($request), OfficePlanValidator::normalize($layout)),
        ]]);
    }

    /**
     * Lister les plans.
     *
     * Du plus récent au plus ancien ; `status` filtre, par exemple
     * `status=proposed` pour les propositions en attente.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(OfficePlanStatus::class)]]);

        $plans = $this->company($request)->officePlans()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('version')
            ->limit(100)
            ->get();

        return OfficePlanResource::collection($plans);
    }

    /**
     * Lire un plan.
     */
    public function show(Request $request, int $plan): OfficePlanResource
    {
        return new OfficePlanResource($this->find($request, $plan));
    }

    /**
     * Accepter une proposition.
     *
     * Elle devient le plan en vigueur ; l'ancien est remplacé et les autres
     * propositions deviennent périmées. Revalidée contre l'organisation
     * actuelle (422) ; 409 si elle n'est plus en attente ; réservé au CEO.
     */
    public function accept(Request $request, int $plan): OfficePlanResource
    {
        $this->assertCeo();
        $data = $request->validate(['comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return new OfficePlanResource($this->plans->accept(
            $this->company($request),
            $this->find($request, $plan),
            $data['comment'] ?? null,
        ));
    }

    /**
     * Refuser une proposition.
     *
     * 409 si elle n'est plus en attente ; réservé au CEO.
     */
    public function reject(Request $request, int $plan): OfficePlanResource
    {
        $this->assertCeo();
        $data = $request->validate(['comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return new OfficePlanResource($this->plans->reject($this->find($request, $plan), $data['comment'] ?? null));
    }

    private function find(Request $request, int $id): OfficePlan
    {
        return $this->company($request)->officePlans()->findOrFail($id);
    }

    /**
     * D-012 : seul le CEO décide de l'aménagement.
     */
    private function assertCeo(): void
    {
        if (! $this->actingAgent->isCeo()) {
            throw new AccessDeniedHttpException("Seul le CEO décide de l'aménagement du bureau.");
        }
    }
}
