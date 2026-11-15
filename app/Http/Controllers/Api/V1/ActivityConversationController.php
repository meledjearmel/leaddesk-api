<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Activity;
use App\Models\ActivityContextItem;
use App\Models\ActivityMessage;
use App\Models\Agent;
use App\Support\ActingAgent;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Conversation de travail et contexte partagé d'une activité.
 *
 * Participants : le CEO, les agents affectés à une tâche de l'activité et
 * les responsables de ses équipes. Seuls les participants écrivent ; les
 * fichiers restent sur l'ordinateur hôte, un message n'en porte que la
 * référence (chemin et ordinateur).
 */
#[Group('Conversation', weight: 11)]
class ActivityConversationController extends ApiController
{
    public function __construct(private readonly ActingAgent $actingAgent) {}

    /**
     * Lire la conversation.
     *
     * Messages du plus ancien au plus récent ; `after_id` ne renvoie que les
     * messages postérieurs (rechargement après `company.changed`). Renvoie
     * aussi les participants.
     *
     * @return array{data: list<array<string, mixed>>, participants: array{ceo: bool, agent_ids: list<int>}}
     */
    public function messages(Request $request, int $activity): array
    {
        $filters = $request->validate([
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'task_id' => ['sometimes', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $model = $this->activity($request, $activity);

        $messages = ActivityMessage::query()
            ->where('activity_id', $model->id)
            ->when(isset($filters['after_id']), fn ($q) => $q->where('id', '>', $filters['after_id']))
            ->when(isset($filters['task_id']), fn ($q) => $q->where('task_id', $filters['task_id']))
            ->orderBy('id')
            ->limit($filters['limit'] ?? 100)
            ->get();

        return [
            'data' => array_values($messages->map(fn (ActivityMessage $m) => $this->message($m))->all()),
            'participants' => ['ceo' => true, 'agent_ids' => array_values($this->participantIds($model)->all())],
        ];
    }

    /**
     * Écrire dans la conversation.
     *
     * Au nom d'un agent (`X-LeadDesk-Agent`), il doit participer à
     * l'activité, sinon 403. `file_references` désigne des fichiers restés
     * sur l'ordinateur hôte : leur contenu ne transite jamais par le serveur.
     *
     * @return array{data: array<string, mixed>}
     */
    public function storeMessage(Request $request, int $activity): array
    {
        $company = $this->company($request);
        $model = $this->activity($request, $activity);
        $agent = $this->ensureParticipant($model);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'kind' => ['sometimes', Rule::in(ActivityMessage::KINDS)],
            'task_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tasks', 'id')->where('activity_id', $model->id)->whereNull('deleted_at')],
            'file_references' => ['sometimes', 'nullable', 'array', 'max:20'],
            'file_references.*.label' => ['required', 'string', 'max:160'],
            'file_references.*.path' => ['required', 'string', 'max:1000'],
            'file_references.*.device_id' => ['required', 'integer', Rule::exists('devices', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
        ]);

        $message = ActivityMessage::query()->create([
            'company_id' => $company->id,
            'activity_id' => $model->id,
            'task_id' => $data['task_id'] ?? null,
            'agent_id' => $agent?->id,
            'kind' => $data['kind'] ?? 'message',
            'body' => $data['body'],
            'file_references' => isset($data['file_references']) ? array_values($data['file_references']) : null,
        ]);

        return ['data' => $this->message($message)];
    }

    /**
     * Retirer un message (corbeille).
     *
     * Réservé au CEO.
     */
    public function destroyMessage(Request $request, int $activity, int $message): Response
    {
        $this->ensureCeo();
        $model = $this->activity($request, $activity);

        ActivityMessage::query()->where('activity_id', $model->id)->findOrFail($message)->delete();

        return response()->noContent();
    }

    /**
     * Lire le contexte partagé.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function context(Request $request, int $activity): array
    {
        $model = $this->activity($request, $activity);

        $items = ActivityContextItem::query()
            ->where('activity_id', $model->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return ['data' => array_values($items->map(fn (ActivityContextItem $i) => $this->item($i))->all())];
    }

    /**
     * Ajouter un élément au contexte partagé.
     *
     * Ouvert au CEO et aux participants.
     *
     * @return array{data: array<string, mixed>}
     */
    public function storeContext(Request $request, int $activity): array
    {
        $company = $this->company($request);
        $model = $this->activity($request, $activity);
        $agent = $this->ensureParticipant($model);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'content' => ['required', 'string', 'max:20000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $item = ActivityContextItem::query()->create([
            'company_id' => $company->id,
            'activity_id' => $model->id,
            'agent_id' => $agent?->id,
            'title' => $data['title'],
            'content' => $data['content'],
            'position' => $data['position'] ?? 0,
        ]);

        return ['data' => $this->item($item)];
    }

    /**
     * Modifier un élément du contexte partagé.
     *
     * @return array{data: array<string, mixed>}
     */
    public function updateContext(Request $request, int $activity, int $item): array
    {
        $model = $this->activity($request, $activity);
        $this->ensureParticipant($model);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'content' => ['sometimes', 'string', 'max:20000'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $record = ActivityContextItem::query()->where('activity_id', $model->id)->findOrFail($item);
        $record->update($data);

        return ['data' => $this->item($record)];
    }

    /**
     * Retirer un élément du contexte partagé (corbeille).
     */
    public function destroyContext(Request $request, int $activity, int $item): Response
    {
        $model = $this->activity($request, $activity);
        $this->ensureParticipant($model);

        ActivityContextItem::query()->where('activity_id', $model->id)->findOrFail($item)->delete();

        return response()->noContent();
    }

    private function activity(Request $request, int $id): Activity
    {
        return $this->company($request)->activities()->findOrFail($id);
    }

    /**
     * Agents qui participent : affectés à une tâche de l'activité, ou
     * responsables d'une de ses équipes.
     *
     * @return Collection<int, int>
     */
    private function participantIds(Activity $activity): Collection
    {
        $assigned = DB::table('agent_task')
            ->join('tasks', 'tasks.id', '=', 'agent_task.task_id')
            ->where('tasks.activity_id', $activity->id)
            ->whereNull('tasks.deleted_at')
            ->pluck('agent_task.agent_id');

        $responsibles = $activity->units()->get()
            ->map(fn ($unit) => $unit->responsibleAgent()?->id)
            ->filter();

        return $assigned->merge($responsibles)->map(fn ($id) => (int) $id)->unique()->sort()->values();
    }

    private function ensureParticipant(Activity $activity): ?Agent
    {
        $agent = $this->actingAgent->get();

        if ($agent !== null && ! $this->participantIds($activity)->contains($agent->id)) {
            throw new AccessDeniedHttpException('Cet agent ne participe pas à cette activité.');
        }

        return $agent;
    }

    private function ensureCeo(): void
    {
        if (! $this->actingAgent->isCeo()) {
            throw new AccessDeniedHttpException('Seul le CEO retire un message.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ActivityMessage $m): array
    {
        return [
            'id' => $m->id,
            'activity_id' => $m->activity_id,
            'task_id' => $m->task_id,
            'author' => $m->agent_id === null ? ['type' => 'ceo', 'agent_id' => null] : ['type' => 'agent', 'agent_id' => $m->agent_id],
            'kind' => $m->kind,
            'body' => $m->body,
            'file_references' => $m->file_references ?? [],
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ActivityContextItem $i): array
    {
        return [
            'id' => $i->id,
            'activity_id' => $i->activity_id,
            'agent_id' => $i->agent_id,
            'title' => $i->title,
            'content' => $i->content,
            'position' => $i->position,
            'created_at' => $i->created_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }
}
