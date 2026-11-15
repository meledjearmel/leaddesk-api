<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Mission;
use App\Support\ActingAgent;
use App\Support\CompanySettings;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Missions : la barre « Donner une mission » du bureau.
 *
 * Le CEO confie une mission en une phrase ; elle va à l'agent en service qui
 * occupe le poste des missions (réglage `mission_position_id`, par défaut le
 * poste de tête : le COO du modèle studio). Cet agent l'organise en
 * activité, puis la rend.
 */
#[Group('Missions', weight: 12)]
class MissionController extends ApiController
{
    public function __construct(private readonly ActingAgent $actingAgent) {}

    /**
     * Lister les missions.
     *
     * Les plus récentes d'abord ; `status` filtre.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(Request $request): array
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Mission::STATUSES)],
        ]);

        $missions = Mission::query()
            ->where('company_id', $this->company($request)->id)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('id')
            ->get();

        return ['data' => array_values($missions->map(fn (Mission $m) => $this->present($m))->all())];
    }

    /**
     * Confier une mission.
     *
     * Réservé au CEO. Répond 409 si aucun agent en service n'occupe le poste
     * des missions : il faut d'abord en mettre un en service ou régler un
     * autre poste (`PATCH /company/settings`).
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureCeo('Seul le CEO confie une mission.');
        $company = $this->company($request);

        $data = $request->validate([
            'brief' => ['required', 'string', 'max:5000'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
        ]);

        $agent = CompanySettings::missionAgent($company);

        if ($agent === null) {
            throw new ConflictHttpException('Aucun agent en service n\'occupe le poste qui reçoit les missions.');
        }

        $mission = Mission::query()->create([
            'company_id' => $company->id,
            'position_id' => $agent->position_id,
            'agent_id' => $agent->id,
            'project_id' => $data['project_id'] ?? null,
            'brief' => $data['brief'],
            'status' => 'received',
        ]);

        return response()->json(['data' => $this->present($mission)], Response::HTTP_CREATED);
    }

    /**
     * Lire une mission.
     *
     * @return array{data: array<string, mixed>}
     */
    public function show(Request $request, int $mission): array
    {
        return ['data' => $this->present($this->find($request, $mission))];
    }

    /**
     * Rattacher la mission à l'activité qui la réalise.
     *
     * Par l'agent qui a reçu la mission, ou par le CEO. La mission passe en
     * cours.
     *
     * @return array{data: array<string, mixed>}
     */
    public function link(Request $request, int $mission): array
    {
        $company = $this->company($request);
        $model = $this->openMission($request, $mission);
        $this->ensureRecipientOrCeo($model);

        $data = $request->validate([
            'activity_id' => ['required', 'integer', Rule::exists('activities', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
        ]);

        $model->update(['activity_id' => $data['activity_id'], 'status' => 'in_progress']);

        return ['data' => $this->present($model)];
    }

    /**
     * Rendre la mission.
     *
     * Par l'agent qui l'a reçue, ou par le CEO, avec un compte rendu.
     *
     * @return array{data: array<string, mixed>}
     */
    public function complete(Request $request, int $mission): array
    {
        $model = $this->openMission($request, $mission);
        $this->ensureRecipientOrCeo($model);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'max:20000'],
        ]);

        $model->update(['status' => 'done', 'outcome' => $data['outcome'], 'closed_at' => now()]);

        return ['data' => $this->present($model)];
    }

    /**
     * Annuler la mission.
     *
     * Réservé au CEO.
     *
     * @return array{data: array<string, mixed>}
     */
    public function cancel(Request $request, int $mission): array
    {
        $this->ensureCeo('Seul le CEO annule une mission.');
        $model = $this->openMission($request, $mission);

        $model->update(['status' => 'cancelled', 'closed_at' => now()]);

        return ['data' => $this->present($model)];
    }

    private function find(Request $request, int $id): Mission
    {
        return Mission::query()->where('company_id', $this->company($request)->id)->findOrFail($id);
    }

    private function openMission(Request $request, int $id): Mission
    {
        $mission = $this->find($request, $id);

        if (! $mission->isOpen()) {
            throw new ConflictHttpException('Cette mission est déjà close.');
        }

        return $mission;
    }

    private function ensureCeo(string $message): void
    {
        if (! $this->actingAgent->isCeo()) {
            throw new AccessDeniedHttpException($message);
        }
    }

    private function ensureRecipientOrCeo(Mission $mission): void
    {
        $agent = $this->actingAgent->get();

        if ($agent !== null && $agent->id !== $mission->agent_id) {
            throw new AccessDeniedHttpException('Seul l\'agent qui a reçu la mission la fait avancer.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Mission $m): array
    {
        return [
            'id' => $m->id,
            'brief' => $m->brief,
            'status' => $m->status,
            'position_id' => $m->position_id,
            'agent_id' => $m->agent_id,
            'project_id' => $m->project_id,
            'activity_id' => $m->activity_id,
            'outcome' => $m->outcome,
            'closed_at' => $m->closed_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }
}
