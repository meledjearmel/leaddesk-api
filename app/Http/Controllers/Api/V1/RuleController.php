<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Rules\RuleChange;
use App\Http\Resources\V1\DecisionResource;
use App\Http\Resources\V1\RuleResource;
use App\Models\Activity;
use App\Models\Decision;
use App\Models\Rule;
use App\Models\Unit;
use App\Support\ActingAgent;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Règles : elles suivent la hiérarchie et s'appliquent vers les niveaux
 * inférieurs (RM-24). Seul le CEO humain les change (RM-25) ; les agents
 * proposent.
 */
#[Group('Règles', weight: 13)]
class RuleController extends ApiController
{
    public function __construct(
        private readonly RuleChange $ruleChange,
        private readonly ActingAgent $actingAgent,
    ) {}

    /**
     * Lister les règles.
     *
     * Avec `unit_id`, renvoie les règles en vigueur pour cette unité : celles
     * de l'entreprise, des unités au-dessus d'elle et les siennes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['unit_id' => ['sometimes', 'integer']]);
        $company = $this->company($request);
        $rules = $company->rules()->with('unit')->orderBy('id')->get();

        if (isset($filters['unit_id'])) {
            $unit = $company->units()->whereKey($filters['unit_id'])->firstOrFail();
            $rules = $this->inForce($rules, $unit);
        }

        return RuleResource::collection($rules);
    }

    /**
     * Lire les règles applicables à une activité.
     *
     * Règles en vigueur pour ses équipes. Une règle créée ou modifiée après
     * le début de l'activité ne s'y applique que si le CEO l'a étendue aux
     * activités en cours (RM-27).
     */
    public function forActivity(Request $request, int $activity): AnonymousResourceCollection
    {
        $company = $this->company($request);
        $model = $company->activities()->with('units')->findOrFail($activity);
        $rules = $company->rules()->with('unit')->orderBy('id')->get();

        $applicable = $rules->filter(function (Rule $rule) use ($model): bool {
            $concernsATeam = $rule->unit === null
                || $model->units->contains(fn (Unit $unit): bool => $rule->appliesToUnit($unit));

            return $concernsATeam && $this->appliesInTime($rule, $model);
        });

        return RuleResource::collection($applicable->values());
    }

    /**
     * Créer une règle.
     *
     * Réservé au CEO. La règle vaut pour les nouvelles activités ; avec
     * `extend_to_running`, elle vaut aussi pour les activités en cours (RM-27).
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureCeo();
        $company = $this->company($request);
        $change = $this->ruleChange->validate($company, [...$request->all(), 'operation' => 'create']);
        $rule = $this->ruleChange->apply($company, $change, $request->boolean('extend_to_running'));

        return (new RuleResource($rule?->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Lire une règle.
     */
    public function show(Request $request, int $rule): RuleResource
    {
        return new RuleResource($this->find($request, $rule));
    }

    /**
     * Modifier une règle.
     *
     * Réservé au CEO. Crée une nouvelle version, en vigueur pour les nouvelles
     * activités ; `extend_to_running` l'étend aux activités en cours (RM-27).
     */
    public function update(Request $request, int $rule): RuleResource
    {
        $this->ensureCeo();
        $company = $this->company($request);
        $model = $this->find($request, $rule);
        $change = $this->ruleChange->validate($company, [
            ...$request->only(['title', 'body']),
            'operation' => 'update',
            'rule_id' => $model->id,
        ]);

        return new RuleResource($this->ruleChange->apply($company, $change, $request->boolean('extend_to_running')));
    }

    /**
     * Étendre une règle aux activités en cours.
     *
     * Décision explicite du CEO (RM-27).
     */
    public function extendToRunning(Request $request, int $rule): RuleResource
    {
        $this->ensureCeo();
        $model = $this->find($request, $rule);
        $model->update(['extended_to_running' => true]);

        return new RuleResource($model);
    }

    /**
     * Retirer une règle.
     *
     * Réservé au CEO. Refusé (409) tant que des règles de niveaux inférieurs
     * la précisent ou la renforcent.
     */
    public function destroy(Request $request, int $rule): Response
    {
        $this->ensureCeo();
        $model = $this->find($request, $rule);

        if ($model->refinements()->exists()) {
            throw new ConflictHttpException('Des règles de niveaux inférieurs précisent ou renforcent cette règle.');
        }

        $this->ruleChange->apply($this->company($request), ['operation' => 'archive', 'rule_id' => $model->id]);

        return response()->noContent();
    }

    /**
     * Proposer un changement de règle.
     *
     * Ouvert aux agents comme au CEO virtuel : la proposition arrive dans les
     * décisions en attente du CEO. La règle existante reste en vigueur tant
     * que le CEO n'a pas décidé (RM-26).
     */
    public function propose(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $change = $this->ruleChange->validate($company, $request->all());

        // RM-28 : ce que présente une proposition.
        $context = $request->validate([
            'problem' => ['required', 'string', 'max:5000'],
            'affected' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_effects' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'risks' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $target = isset($change['rule_id']) ? $company->rules()->whereKey($change['rule_id'])->first() : null;

        $decision = $company->decisions()->create([
            'kind' => Decision::KIND_RULE_CHANGE,
            'title' => $this->proposalTitle($change, $target),
            'requested_by_agent_id' => $this->actingAgent->get()?->id,
            'subject_type' => $target?->getMorphClass(),
            'subject_id' => $target?->id,
            'payload' => ['change' => $change, ...$context],
        ]);

        return (new DecisionResource($decision->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(Request $request, int $id): Rule
    {
        return $this->company($request)->rules()->findOrFail($id);
    }

    /**
     * RM-25 : seul le CEO humain décide des changements de règles. Aucun
     * agent, CEO virtuel compris, ne modifie une règle.
     */
    private function ensureCeo(): void
    {
        if (! $this->actingAgent->isCeo()) {
            throw new AccessDeniedHttpException(
                'Seul le CEO change les règles : un agent passe par une proposition.',
            );
        }
    }

    /**
     * @param  Collection<int, Rule>  $rules
     * @return Collection<int, Rule>
     */
    private function inForce(Collection $rules, Unit $unit): Collection
    {
        return $rules->filter(fn (Rule $rule): bool => $rule->appliesToUnit($unit))->values();
    }

    private function appliesInTime(Rule $rule, Activity $activity): bool
    {
        return $rule->extended_to_running
            || $activity->created_at === null
            || $rule->effective_at->lessThanOrEqualTo($activity->created_at);
    }

    /**
     * @param  array{operation: string, title?: string|null}  $change
     */
    private function proposalTitle(array $change, ?Rule $target): string
    {
        return match ($change['operation']) {
            'create' => 'Nouvelle règle : '.($change['title'] ?? ''),
            'archive' => 'Retirer la règle : '.($target->title ?? ''),
            default => 'Modifier la règle : '.($target->title ?? ''),
        };
    }
}
