<?php

namespace App\Actions\Costs;

use App\Enums\RunStatus;
use App\Models\CostLimit;
use App\Models\Decision;
use App\Models\TaskRun;
use Illuminate\Validation\ValidationException;

/**
 * Applique la décision du CEO sur une limite de coût atteinte (RM-36).
 */
class ResolveCostLimitDecision
{
    /**
     * Le CEO relève la limite : l'exécution reprend.
     */
    public function approve(Decision $decision, ?int $newLimitMicros): void
    {
        $payload = $decision->payload ?? [];
        $spent = (int) ($payload['spent_micros'] ?? 0);

        if ($newLimitMicros === null || $newLimitMicros <= $spent) {
            throw ValidationException::withMessages([
                'new_limit_micros' => ['Indiquez une nouvelle limite supérieure au coût déjà dépensé.'],
            ]);
        }

        CostLimit::query()->updateOrCreate(
            [
                'company_id' => $decision->company_id,
                'scope' => $payload['scope'] ?? 'task',
                'scope_id' => $payload['scope_id'] ?? 0,
            ],
            ['limit_micros' => $newLimitMicros],
        );

        $run = $this->run($decision);

        if ($run?->status === RunStatus::PausedCost) {
            $run->update(['status' => RunStatus::Running, 'status_reason' => null]);
        }
    }

    /**
     * Le CEO refuse de dépenser plus : l'exécution s'arrête.
     */
    public function reject(Decision $decision): void
    {
        $run = $this->run($decision);

        if ($run?->status === RunStatus::PausedCost) {
            $run->update([
                'status' => RunStatus::Cancelled,
                'status_reason' => 'Arrêtée par le CEO : limite de coût atteinte.',
                'ended_at' => now(),
            ]);
        }
    }

    private function run(Decision $decision): ?TaskRun
    {
        return TaskRun::query()
            ->where('company_id', $decision->company_id)
            ->whereKey($decision->payload['task_run_id'] ?? 0)
            ->first();
    }
}
