<?php

namespace App\Actions\Costs;

use App\Enums\CostScope;
use App\Enums\DecisionStatus;
use App\Enums\RunStatus;
use App\Models\CostLimit;
use App\Models\Decision;
use App\Models\TaskRun;
use App\Models\UsageRecord;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre le coût réel d'un appel et applique les limites de coût.
 *
 * RM-35 : le coût réel est rattaché à l'agent, à la tâche, à l'activité et au
 * projet. RM-36 : quand une limite est atteinte, l'exécution se met en pause
 * et une décision attend le CEO.
 */
class RecordUsage
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{kind?: string, provider?: string|null, model?: string|null, input_tokens?: int, output_tokens?: int, cache_read_tokens?: int, cache_write_tokens?: int, cost_micros?: int|null, occurred_at?: string|null}  $data
     */
    public function handle(TaskRun $run, array $data): UsageRecord
    {
        return DB::transaction(function () use ($run, $data): UsageRecord {
            $task = $run->task;

            $record = UsageRecord::query()->create([
                'company_id' => $run->company_id,
                'task_run_id' => $run->id,
                'agent_id' => $run->agent_id,
                'task_id' => $task->id,
                'activity_id' => $task->activity_id,
                'project_id' => $task->activity->project_id,
                'kind' => $data['kind'] ?? 'model',
                'provider' => $data['provider'] ?? null,
                'model' => $data['model'] ?? null,
                'input_tokens' => $data['input_tokens'] ?? 0,
                'output_tokens' => $data['output_tokens'] ?? 0,
                'cache_read_tokens' => $data['cache_read_tokens'] ?? 0,
                'cache_write_tokens' => $data['cache_write_tokens'] ?? 0,
                'cost_micros' => $data['cost_micros'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $this->enforceLimits($run);

            return $record;
        });
    }

    /**
     * Limite atteinte parmi celles qui concernent cette exécution, de la plus
     * précise à la plus large : tâche, projet, entreprise.
     *
     * @return array{limit: CostLimit, spent: int}|null
     */
    public function reachedLimit(TaskRun $run): ?array
    {
        $task = $run->task;
        $projectId = $task->activity->project_id;

        return $this->check($run, CostScope::Task, $task->id, 'task_id')
            ?? ($projectId === null ? null : $this->check($run, CostScope::Project, $projectId, 'project_id'))
            ?? $this->check($run, CostScope::Company, 0, null);
    }

    /**
     * @return array{limit: CostLimit, spent: int}|null
     */
    private function check(TaskRun $run, CostScope $scope, int $scopeId, ?string $column): ?array
    {
        $limit = CostLimit::query()
            ->where('company_id', $run->company_id)
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->first();

        if ($limit === null) {
            return null;
        }

        $spent = (int) UsageRecord::query()
            ->where('company_id', $run->company_id)
            ->when($column !== null, fn ($query) => $query->where((string) $column, $scopeId))
            ->sum('cost_micros');

        return $spent >= $limit->limit_micros ? ['limit' => $limit, 'spent' => $spent] : null;
    }

    private function enforceLimits(TaskRun $run): void
    {
        if ($run->status !== RunStatus::Running) {
            return;
        }

        $reached = $this->reachedLimit($run);

        if ($reached === null) {
            return;
        }

        ['limit' => $limit, 'spent' => $spent] = $reached;

        // RM-36 : pause automatique…
        $run->update([
            'status' => RunStatus::PausedCost,
            'status_reason' => 'Limite de coût atteinte.',
        ]);

        // … et une seule décision en attente par exécution.
        $alreadyAsked = Decision::query()
            ->where('company_id', $run->company_id)
            ->where('kind', Decision::KIND_COST_LIMIT)
            ->whereIn('status', [DecisionStatus::Pending, DecisionStatus::NeedsInfo])
            ->where('payload->task_run_id', $run->id)
            ->exists();

        if ($alreadyAsked) {
            return;
        }

        $decision = Decision::query()->create([
            'company_id' => $run->company_id,
            'kind' => Decision::KIND_COST_LIMIT,
            'title' => 'Limite de coût atteinte : '.$run->task->title,
            'requested_by_agent_id' => null,
            'subject_type' => $run->task->getMorphClass(),
            'subject_id' => $run->task_id,
            'payload' => [
                'task_run_id' => $run->id,
                'scope' => $limit->scope->value,
                'scope_id' => $limit->scope_id,
                'limit_micros' => $limit->limit_micros,
                'spent_micros' => $spent,
            ],
        ]);

        $this->audit->record($run->company_id, 'task_run.paused_for_cost', $run, [
            'decision_id' => $decision->id,
            'scope' => $limit->scope->value,
            'limit_micros' => $limit->limit_micros,
            'spent_micros' => $spent,
        ]);
    }
}
