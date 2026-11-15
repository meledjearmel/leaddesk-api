<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\DeviceResource;
use App\Models\Device;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Ordinateurs du CEO. Les agents s'exécutent sur l'ordinateur hôte d'une
 * tâche, et les fichiers de travail n'en sortent jamais.
 */
#[Group('Ordinateurs', weight: 15)]
class DeviceController extends ApiController
{
    /**
     * Lister les ordinateurs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return DeviceResource::collection($this->company($request)->devices()->orderBy('id')->get());
    }

    /**
     * Enregistrer cet ordinateur.
     *
     * Appelé par l'application à son ouverture. Le même `installation_id`
     * retrouve le même ordinateur : rien n'est créé en double.
     */
    public function store(Request $request): DeviceResource
    {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::in(['windows', 'macos', 'linux', 'android'])],
        ]);

        $device = $this->company($request)->devices()->updateOrCreate(
            ['installation_id' => $data['installation_id']],
            ['name' => $data['name'], 'platform' => $data['platform'], 'last_seen_at' => now()],
        );

        return new DeviceResource($device);
    }

    /**
     * Signaler que l'ordinateur est en ligne.
     *
     * À appeler régulièrement tant que l'application est ouverte. Sans signe
     * de vie, les exécutions de cet hôte sont affichées « hôte hors ligne ».
     */
    public function heartbeat(Request $request, int $device): DeviceResource
    {
        $model = $this->find($request, $device);
        $model->timestamps = false;
        $model->forceFill(['last_seen_at' => now()])->saveQuietly();

        return new DeviceResource($model);
    }

    /**
     * Renommer un ordinateur.
     */
    public function update(Request $request, int $device): DeviceResource
    {
        $model = $this->find($request, $device);
        $model->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return new DeviceResource($model);
    }

    /**
     * Retirer un ordinateur.
     *
     * Refusé (409) tant qu'une exécution est en cours sur cet hôte.
     */
    public function destroy(Request $request, int $device): Response
    {
        $model = $this->find($request, $device);

        if ($this->company($request)->taskRuns()->where('device_id', $model->id)->whereNull('ended_at')->exists()) {
            throw new ConflictHttpException('Une exécution est en cours sur cet ordinateur.');
        }

        $model->delete();

        return response()->noContent();
    }

    private function find(Request $request, int $id): Device
    {
        return $this->company($request)->devices()->findOrFail($id);
    }
}
