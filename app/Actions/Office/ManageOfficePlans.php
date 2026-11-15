<?php

namespace App\Actions\Office;

use App\Enums\DecisionStatus;
use App\Enums\OfficePlanStatus;
use App\Models\Agent;
use App\Models\Company;
use App\Models\Decision;
use App\Models\OfficePlan;
use App\Office\CompanyOfficeDepartments;
use App\Office\OfficePlanValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cycle de vie des plans d'aménagement (D-012, D-036).
 *
 * - Un poste des Moyens généraux propose ; la proposition attend le CEO.
 * - Le CEO accepte ou refuse chaque proposition, ou dessine lui-même le plan.
 * - Un seul plan en vigueur : le précédent passe « remplacé ».
 * - Quand le plan en vigueur change, les propositions en attente passent
 *   « périmées ».
 *
 * Tout plan passe par les mêmes règles que l'application, contre
 * l'organisation actuelle ; un plan invalide n'est jamais enregistré.
 *
 * @phpstan-import-type Layout from OfficePlanValidator
 */
class ManageOfficePlans
{
    public function __construct(
        private readonly OfficePlanValidator $validator,
        private readonly CompanyOfficeDepartments $departments,
    ) {}

    /**
     * @param  Layout  $layout
     * @return list<string>
     */
    public function problems(Company $company, array $layout): array
    {
        return $this->validator->validate($layout, $this->departments->for($company));
    }

    public function current(Company $company): ?OfficePlan
    {
        return $company->officePlans()->where('status', OfficePlanStatus::Accepted)->first();
    }

    /**
     * Enregistre une proposition d'un agent. Refusée (422) si le plan est invalide.
     *
     * @param  Layout  $layout
     */
    public function propose(Company $company, Agent $agent, array $layout, ?string $reason): OfficePlan
    {
        $this->assertValid($company, $layout);

        return DB::transaction(function () use ($company, $agent, $layout, $reason): OfficePlan {
            $plan = $company->officePlans()->create([
                'version' => $this->nextVersion($company),
                'status' => OfficePlanStatus::Proposed,
                'layout' => $layout,
                'proposed_by_agent_id' => $agent->id,
                'proposed_by_position_id' => $agent->position_id,
                'based_on_id' => $this->current($company)?->id,
                'reason' => $reason,
            ]);

            // D-012 : la proposition attend le CEO, avec ses autres décisions.
            $company->decisions()->create([
                'kind' => Decision::KIND_OFFICE_PLAN,
                'title' => "Réaménagement du bureau (version {$plan->version})",
                'status' => DecisionStatus::Pending,
                'requested_by_agent_id' => $agent->id,
                'subject_type' => $plan->getMorphClass(),
                'subject_id' => $plan->id,
                'payload' => ['office_plan_id' => $plan->id, 'reason' => $reason],
            ]);

            return $plan;
        });
    }

    /**
     * Le CEO dessine le plan lui-même : il entre en vigueur sans approbation.
     *
     * @param  Layout  $layout
     */
    public function saveByCeo(Company $company, array $layout): OfficePlan
    {
        $this->assertValid($company, $layout);

        return DB::transaction(function () use ($company, $layout): OfficePlan {
            $previous = $this->current($company);
            $this->retire($company, $previous);

            return $company->officePlans()->create([
                'version' => $this->nextVersion($company),
                'status' => OfficePlanStatus::Accepted,
                'layout' => $layout,
                'based_on_id' => $previous?->id,
                'decided_at' => now(),
            ]);
        });
    }

    public function accept(Company $company, OfficePlan $plan, ?string $comment): OfficePlan
    {
        $this->assertProposed($plan);
        // L'organisation a pu changer depuis la proposition : on revalide.
        $this->assertValid($company, $plan->layout);

        return DB::transaction(function () use ($company, $plan, $comment): OfficePlan {
            $this->retire($company, $this->current($company), except: $plan);

            $plan->update([
                'status' => OfficePlanStatus::Accepted,
                'decision_comment' => $comment,
                'decided_at' => now(),
            ]);
            $this->closeDecision($plan, DecisionStatus::Approved, $comment);

            return $plan;
        });
    }

    public function reject(OfficePlan $plan, ?string $comment): OfficePlan
    {
        $this->assertProposed($plan);

        DB::transaction(function () use ($plan, $comment): void {
            $plan->update([
                'status' => OfficePlanStatus::Rejected,
                'decision_comment' => $comment,
                'decided_at' => now(),
            ]);
            $this->closeDecision($plan, DecisionStatus::Rejected, $comment);
        });

        return $plan;
    }

    /**
     * Le plan en vigueur passe « remplacé », les autres propositions « périmées ».
     */
    private function retire(Company $company, ?OfficePlan $current, ?OfficePlan $except = null): void
    {
        $current?->update(['status' => OfficePlanStatus::Superseded]);

        $company->officePlans()
            ->where('status', OfficePlanStatus::Proposed)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->get()
            ->each(function (OfficePlan $proposal): void {
                $proposal->update(['status' => OfficePlanStatus::Stale]);
                $this->closeDecision($proposal, DecisionStatus::Rejected, 'Périmée : le plan du bureau a changé entre-temps.');
            });
    }

    /**
     * Ferme la décision ouverte liée à une proposition, s'il y en a une.
     */
    private function closeDecision(OfficePlan $plan, DecisionStatus $status, ?string $note): void
    {
        Decision::query()
            ->where('company_id', $plan->company_id)
            ->where('kind', Decision::KIND_OFFICE_PLAN)
            ->where('subject_type', $plan->getMorphClass())
            ->where('subject_id', $plan->id)
            ->whereIn('status', [DecisionStatus::Pending, DecisionStatus::NeedsInfo])
            ->get()
            ->each(fn (Decision $decision) => $decision->update([
                'status' => $status,
                'decision_note' => $note,
                'decided_at' => now(),
            ]));
    }

    /**
     * Proposition liée à une décision du CEO.
     */
    public function planOf(Decision $decision): OfficePlan
    {
        /** @var int|null $id */
        $id = $decision->payload['office_plan_id'] ?? $decision->subject_id;

        return OfficePlan::query()->where('company_id', $decision->company_id)->findOrFail($id);
    }

    private function assertProposed(OfficePlan $plan): void
    {
        if ($plan->status !== OfficePlanStatus::Proposed) {
            throw new ConflictHttpException("Ce plan n'est plus en attente de décision.");
        }
    }

    /**
     * @param  Layout  $layout
     */
    private function assertValid(Company $company, array $layout): void
    {
        $problems = $this->problems($company, $layout);

        if ($problems !== []) {
            throw ValidationException::withMessages(['layout' => $problems]);
        }
    }

    private function nextVersion(Company $company): int
    {
        return (int) $company->officePlans()->withTrashed()->max('version') + 1;
    }
}
