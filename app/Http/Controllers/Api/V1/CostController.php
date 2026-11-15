<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CostScope;
use App\Models\CostLimit;
use App\Support\ActingAgent;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Coûts : coûts réels rattachés au travail, estimations tenues à part
 * (RM-35), et limites réglées par le CEO (RM-36).
 */
#[Group('Coûts', weight: 17)]
class CostController extends ApiController
{
    /**
     * Lire la synthèse des coûts.
     *
     * Coûts réels regroupés par agent, tâche, activité, projet ou modèle.
     * Les estimations ne sont jamais additionnées aux coûts réels : elles
     * figurent dans un champ séparé.
     *
     * @return array{data: array{group_by: string, real_micros: int, unpriced_calls: int, estimated_micros: int, groups: list<array{id: int|string|null, real_micros: int, calls: int, unpriced_calls: int}>}}
     */
    public function summary(Request $request): array
    {
        $filters = $request->validate([
            'group_by' => ['sometimes', Rule::in(['agent', 'task', 'activity', 'project', 'model'])],
            'project_id' => ['sometimes', 'integer'],
        ]);

        $company = $this->company($request);
        $groupBy = $request->string('group_by', 'project')->toString();
        $column = $groupBy === 'model' ? 'model' : $groupBy.'_id';

        $base = fn () => $company->usageRecords()
            ->when(isset($filters['project_id']), fn ($query) => $query->where('project_id', $filters['project_id'] ?? 0));

        $groups = array_values($base()
            ->select($column)
            ->selectRaw('COALESCE(SUM(cost_micros), 0) as real_micros')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(CASE WHEN cost_micros IS NULL THEN 1 ELSE 0 END) as unpriced_calls')
            ->groupBy($column)
            ->orderBy($column)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'id' => $this->groupKey($row->{$column}),
                'real_micros' => (int) $row->real_micros,
                'calls' => (int) $row->calls,
                'unpriced_calls' => (int) $row->unpriced_calls,
            ])
            ->all());

        $estimated = $company->tasks()
            ->when(
                isset($filters['project_id']),
                fn ($query) => $query->whereHas('activity', fn ($activity) => $activity->where('project_id', $filters['project_id'] ?? 0)),
            )
            ->sum('estimated_cost_micros');

        return ['data' => [
            'group_by' => $groupBy,
            'real_micros' => (int) $base()->sum('cost_micros'),
            'unpriced_calls' => $base()->whereNull('cost_micros')->count(),
            'estimated_micros' => (int) $estimated,
            'groups' => $groups,
        ]];
    }

    /**
     * Clé d'un groupe : un identifiant, un nom de modèle, ou vide.
     */
    private function groupKey(mixed $value): int|string|null
    {
        return match (true) {
            $value === null => null,
            is_numeric($value) => (int) $value,
            default => is_string($value) ? $value : null,
        };
    }

    /**
     * Lister les limites de coût.
     *
     * @return array{data: list<array{scope: string, scope_id: int, limit_micros: int}>}
     */
    public function limits(Request $request): array
    {
        return ['data' => array_values($this->company($request)->costLimits()->orderBy('id')->get()
            ->map(fn (CostLimit $limit): array => [
                'scope' => $limit->scope->value,
                'scope_id' => $limit->scope_id,
                'limit_micros' => $limit->limit_micros,
            ])->all())];
    }

    /**
     * Régler une limite de coût.
     *
     * Réservé au CEO. Niveaux : entreprise, projet ou tâche. `limit_micros`
     * vide retire la limite.
     *
     * @return array{data: array{scope: string, scope_id: int, limit_micros: int|null}}
     */
    public function setLimit(Request $request, ActingAgent $actingAgent): array
    {
        if (! $actingAgent->isCeo()) {
            throw new AccessDeniedHttpException('Seul le CEO règle les limites de coût.');
        }

        $company = $this->company($request);
        $data = $request->validate([
            'scope' => ['required', Rule::enum(CostScope::class)],
            // Identifiant du projet ou de la tâche ; inutile pour l'entreprise.
            'scope_id' => ['required_unless:scope,company', 'nullable', 'integer'],
            // Limite en millionièmes de dollar ; vide pour retirer la limite.
            'limit_micros' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $scope = CostScope::from($data['scope']);
        $scopeId = $scope === CostScope::Company ? 0 : (int) $data['scope_id'];

        $exists = match ($scope) {
            CostScope::Company => true,
            CostScope::Project => $company->projects()->whereKey($scopeId)->exists(),
            CostScope::Task => $company->tasks()->whereKey($scopeId)->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages(['scope_id' => ['Ce projet ou cette tâche est introuvable.']]);
        }

        DB::transaction(function () use ($company, $scope, $scopeId, $data): void {
            $limit = $company->costLimits()->where('scope', $scope)->where('scope_id', $scopeId)->first();

            if ($data['limit_micros'] === null) {
                $limit?->delete();

                return;
            }

            $limit === null
                ? $company->costLimits()->create(['scope' => $scope, 'scope_id' => $scopeId, 'limit_micros' => $data['limit_micros']])
                : $limit->update(['limit_micros' => $data['limit_micros']]);
        });

        return ['data' => [
            'scope' => $scope->value,
            'scope_id' => $scopeId,
            'limit_micros' => $data['limit_micros'],
        ]];
    }
}
