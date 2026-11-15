<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Costs\ResolveCostLimitDecision;
use App\Actions\Rules\RuleChange;
use App\Enums\DecisionStatus;
use App\Http\Resources\V1\DecisionResource;
use App\Models\Decision;
use App\Support\ActingAgent;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Décisions du CEO : la file unique des demandes qui attendent sa décision.
 * Seul le CEO humain approuve, refuse ou demande des précisions (RM-25).
 */
#[Group('Décisions du CEO', weight: 14)]
class DecisionController extends ApiController
{
    public function __construct(
        private readonly ActingAgent $actingAgent,
        private readonly RuleChange $ruleChange,
        private readonly ResolveCostLimitDecision $costLimit,
    ) {}

    /**
     * Lister les décisions.
     *
     * Filtrable par état (`pending`, `needs_info`, `approved`, `rejected`) et
     * par nature. Les plus récentes d'abord.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(DecisionStatus::class)],
            'kind' => ['sometimes', 'string', 'max:32'],
        ]);

        $query = $this->company($request)->decisions()->with('requestedBy')->orderByDesc('id');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        return DecisionResource::collection($query->get());
    }

    /**
     * Lire une décision.
     */
    public function show(Request $request, int $decision): DecisionResource
    {
        return new DecisionResource($this->find($request, $decision)->load('requestedBy'));
    }

    /**
     * Approuver.
     *
     * Réservé au CEO. Pour un changement de règle, la règle est alors créée,
     * modifiée ou retirée ; elle vaut pour les nouvelles activités, et pour
     * les activités en cours seulement avec `extend_to_running` (RM-27).
     */
    public function approve(Request $request, int $decision): DecisionResource
    {
        $model = $this->openDecision($request, $decision);
        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'extend_to_running' => ['sometimes', 'boolean'],
            // Pour une limite de coût atteinte : la nouvelle limite, en millionièmes de dollar.
            'new_limit_micros' => ['sometimes', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($request, $model, $data): void {
            if ($model->kind === Decision::KIND_RULE_CHANGE) {
                $company = $this->company($request);

                try {
                    /** @var array<string, mixed> $proposed */
                    $proposed = $model->payload['change'] ?? [];
                    $change = $this->ruleChange->validate($company, $proposed);
                } catch (ValidationException) {
                    throw new ConflictHttpException(
                        "Cette proposition n'est plus applicable : la règle ou l'unité visée a changé depuis.",
                    );
                }

                $this->ruleChange->apply($company, $change, $request->boolean('extend_to_running'));
            }

            if ($model->kind === Decision::KIND_COST_LIMIT) {
                // RM-36 : le CEO relève la limite, l'exécution reprend.
                $this->costLimit->approve($model, $data['new_limit_micros'] ?? null);
            }

            $model->update([
                'status' => DecisionStatus::Approved,
                'decision_note' => $data['note'] ?? null,
                'decided_at' => now(),
            ]);
        });

        return new DecisionResource($model->load('requestedBy'));
    }

    /**
     * Refuser.
     *
     * Réservé au CEO. Rien ne change : la règle existante reste en vigueur.
     */
    public function reject(Request $request, int $decision): DecisionResource
    {
        $model = $this->openDecision($request, $decision);
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:5000']]);

        if ($model->kind === Decision::KIND_COST_LIMIT) {
            // RM-36 : le CEO refuse de dépenser plus, l'exécution s'arrête.
            $this->costLimit->reject($model);
        }

        $model->update([
            'status' => DecisionStatus::Rejected,
            'decision_note' => $data['note'] ?? null,
            'decided_at' => now(),
        ]);

        return new DecisionResource($model->load('requestedBy'));
    }

    /**
     * Demander des précisions.
     *
     * Réservé au CEO. La demande reste ouverte et attend une réponse.
     */
    public function requestInfo(Request $request, int $decision): DecisionResource
    {
        $model = $this->openDecision($request, $decision);
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);

        $model->update(['status' => DecisionStatus::NeedsInfo, 'decision_note' => $data['note']]);

        return new DecisionResource($model->load('requestedBy'));
    }

    /**
     * Répondre à une demande de précisions.
     *
     * La réponse s'ajoute à la demande, qui repasse en attente de décision.
     */
    public function reply(Request $request, int $decision): DecisionResource
    {
        $model = $this->find($request, $decision);
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);

        if ($model->status !== DecisionStatus::NeedsInfo) {
            throw new ConflictHttpException("Cette demande n'attend pas de précisions.");
        }

        $payload = $model->payload ?? [];
        /** @var list<array<string, mixed>> $replies */
        $replies = $payload['replies'] ?? [];
        $replies[] = [
            'agent_id' => $this->actingAgent->get()?->id,
            'message' => $data['message'],
            'at' => now()->toIso8601ZuluString(),
        ];

        $model->update(['status' => DecisionStatus::Pending, 'payload' => [...$payload, 'replies' => $replies]]);

        return new DecisionResource($model->load('requestedBy'));
    }

    private function find(Request $request, int $id): Decision
    {
        return $this->company($request)->decisions()->findOrFail($id);
    }

    /**
     * Décision encore ouverte, pour le CEO seulement.
     */
    private function openDecision(Request $request, int $id): Decision
    {
        // RM-25 : aucun agent, CEO virtuel compris, ne décide à la place du CEO.
        if (! $this->actingAgent->isCeo()) {
            throw new AccessDeniedHttpException('Seul le CEO prend cette décision.');
        }

        $decision = $this->find($request, $id);

        if (! $decision->status->isOpen()) {
            throw new ConflictHttpException('Cette décision est déjà prise.');
        }

        return $decision;
    }
}
