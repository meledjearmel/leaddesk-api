<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DecisionStatus;
use App\Enums\ToolMode;
use App\Http\Resources\V1\DecisionResource;
use App\Models\Decision;
use App\Models\ProjectFolder;
use App\Support\ActingAgent;
use App\Support\ToolPermissions;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Permissions d'exécution : ce qu'un agent peut faire sur l'ordinateur hôte.
 * Les outils dépendent du poste ; les dossiers dépendent du projet.
 */
#[Group("Permissions d'exécution", weight: 18)]
class ExecutionController extends ApiController
{
    public function __construct(
        private readonly ToolPermissions $permissions,
        private readonly ActingAgent $actingAgent,
    ) {}

    /**
     * Lire le catalogue des outils locaux.
     *
     * @return array{data: array{default_mode: string, tools: list<array{tool: string, label: string}>}}
     */
    public function catalog(): array
    {
        $tools = [];

        foreach ($this->permissions->catalog() as $tool => $label) {
            $tools[] = ['tool' => $tool, 'label' => $label];
        }

        return ['data' => [
            'default_mode' => $this->permissions->defaultMode()->value,
            'tools' => $tools,
        ]];
    }

    /**
     * Régler les permissions d'exécution d'un poste.
     *
     * Réservé au CEO. Pour chaque outil : `allow` (autorisé d'office), `ask`
     * (soumis à approbation) ou `deny`. Un outil absent de la liste reprend le
     * mode par défaut.
     *
     * @return array{data: array{position_id: int, tool_permissions: array<string, string>}}
     */
    public function setPositionPermissions(Request $request, int $position): array
    {
        $this->ensureCeo('Seul le CEO règle les permissions d\'exécution d\'un poste.');

        $model = $this->company($request)->positions()->findOrFail($position);
        $tools = array_keys($this->permissions->catalog());

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(ToolMode::class)],
        ]);

        /** @var array<string, string> $permissions */
        $permissions = $data['permissions'];
        $unknown = array_diff(array_keys($permissions), $tools);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['Outil inconnu : '.implode(', ', $unknown).'.'],
            ]);
        }

        $model->update(['tool_permissions' => $permissions === [] ? null : $permissions]);

        return ['data' => [
            'position_id' => $model->id,
            'tool_permissions' => $this->permissions->resolve($model),
        ]];
    }

    /**
     * Lire le profil d'exécution d'un agent.
     *
     * Ce que l'agent peut faire sur l'ordinateur hôte : le mode de chaque
     * outil, hérité de son poste. Sans poste, tout est interdit.
     *
     * @return array{data: array{agent_id: int, position_id: int|null, tool_permissions: array<string, string>}}
     */
    public function agentProfile(Request $request, int $agent): array
    {
        $model = $this->company($request)->agents()->with('position')->findOrFail($agent);

        return ['data' => [
            'agent_id' => $model->id,
            'position_id' => $model->position_id,
            'tool_permissions' => $this->permissions->resolve($model->position),
        ]];
    }

    /**
     * Demander l'approbation d'une action.
     *
     * Appelé par l'ordinateur hôte avant d'utiliser un outil. Réponse :
     * `allowed` (autorisé d'office, rien à attendre), `denied` (interdit au
     * poste), ou `pending` avec la décision à suivre jusqu'à la réponse du CEO.
     */
    public function requestApproval(Request $request, int $taskRun): JsonResponse
    {
        $company = $this->company($request);
        $run = $company->taskRuns()->with('agent.position')->findOrFail($taskRun);

        if (! $run->status->isOpen()) {
            throw new ConflictHttpException('Cette exécution est terminée.');
        }

        $data = $request->validate([
            'tool' => ['required', 'string', 'max:60'],
            // Ce que l'agent veut faire, en clair pour le CEO.
            'summary' => ['required', 'string', 'max:2000'],
        ]);

        $mode = $this->permissions->modeFor($run->agent->position, $data['tool']);

        if ($mode !== ToolMode::Ask) {
            return response()->json(['data' => [
                'outcome' => $mode === ToolMode::Allow ? 'allowed' : 'denied',
                'decision' => null,
            ]]);
        }

        $decision = $company->decisions()->create([
            'kind' => Decision::KIND_TOOL_APPROVAL,
            'title' => 'Approbation demandée : '.$data['tool'],
            'requested_by_agent_id' => $run->agent_id,
            'subject_type' => $run->getMorphClass(),
            'subject_id' => $run->id,
            'payload' => ['task_run_id' => $run->id, 'tool' => $data['tool'], 'summary' => $data['summary']],
        ]);

        return response()->json(['data' => [
            'outcome' => DecisionStatus::Pending->value,
            'decision' => new DecisionResource($decision->refresh()),
        ]], Response::HTTP_ACCEPTED);
    }

    /**
     * Lire les dossiers autorisés d'un projet.
     *
     * Filtrable par ordinateur.
     *
     * @return array{data: list<array{device_id: int, kind: string, path: string, authorized_at: string|null}>}
     */
    public function folders(Request $request, int $project): array
    {
        $filters = $request->validate(['device_id' => ['sometimes', 'integer']]);
        $company = $this->company($request);
        $model = $company->projects()->findOrFail($project);

        $folders = ProjectFolder::query()
            ->where('company_id', $company->id)
            ->where('project_id', $model->id)
            ->when(isset($filters['device_id']), fn ($query) => $query->where('device_id', $filters['device_id'] ?? 0))
            ->orderBy('device_id')
            ->orderBy('kind')
            ->get();

        return ['data' => array_values($folders->map(fn (ProjectFolder $folder): array => [
            'device_id' => $folder->device_id,
            'kind' => $folder->kind,
            'path' => $folder->path,
            'authorized_at' => $folder->authorized_at->toIso8601ZuluString(),
        ])->all())];
    }

    /**
     * Autoriser les dossiers d'un projet sur un ordinateur.
     *
     * Réservé au CEO : une seule autorisation couvre le dossier de
     * documentation et le dossier de code. Changer un chemin vaut nouvelle
     * autorisation ; un chemin vide retire le dossier.
     */
    public function setFolders(Request $request, int $project): Response
    {
        $this->ensureCeo('Seul le CEO autorise les dossiers d\'un projet.');

        $company = $this->company($request);
        $model = $company->projects()->findOrFail($project);
        $data = $request->validate([
            'device_id' => [
                'required', 'integer',
                Rule::exists('devices', 'id')->where('company_id', $company->id)->whereNull('deleted_at'),
            ],
            // Chemin du dossier de documentation sur cet ordinateur.
            'documentation_path' => ['present', 'nullable', 'string', 'max:1000'],
            // Chemin du dossier de code ou de livrable sur cet ordinateur.
            'code_path' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($company, $model, $data): void {
            foreach (ProjectFolder::KINDS as $kind) {
                $path = $data[$kind.'_path'];
                $key = ['company_id' => $company->id, 'project_id' => $model->id, 'device_id' => $data['device_id'], 'kind' => $kind];
                $existing = ProjectFolder::query()->where($key)->first();

                if ($path === null) {
                    $existing?->delete();

                    continue;
                }

                if ($existing === null) {
                    ProjectFolder::query()->create([...$key, 'path' => $path, 'authorized_at' => now()]);
                } elseif ($existing->path !== $path) {
                    $existing->update(['path' => $path, 'authorized_at' => now()]);
                }
            }
        });

        return response()->noContent();
    }

    private function ensureCeo(string $message): void
    {
        if (! $this->actingAgent->isCeo()) {
            throw new AccessDeniedHttpException($message);
        }
    }
}
