<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CompanyChanged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Temps réel : de quoi se connecter à Reverb (D-040).
 */
#[Group('Temps réel', weight: 10)]
class RealtimeController extends ApiController
{
    /**
     * Connexion au temps réel.
     *
     * Adresse publique de Reverb, clé publique de l'application (protocole
     * Pusher) et canal privé de l'entreprise. Le secret ne quitte jamais le
     * serveur ; l'abonnement au canal passe par `POST /broadcasting/auth`.
     */
    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);
        /** @var array{key?: string|null, options?: array{host?: string|null, port?: int|string|null, scheme?: string|null}} $reverb */
        $reverb = config('broadcasting.connections.reverb', []);
        $options = $reverb['options'] ?? [];
        $scheme = (string) ($options['scheme'] ?? 'https');

        return response()->json(['data' => [
            /** Clé publique de l'application Reverb. */
            'key' => $reverb['key'] ?? null,
            'host' => $options['host'] ?? null,
            'port' => (int) ($options['port'] ?? ($scheme === 'https' ? 443 : 80)),
            /** `wss` si Reverb est servi en HTTPS, `ws` sinon. */
            'scheme' => $scheme === 'https' ? 'wss' : 'ws',
            'channel' => 'private-'.CompanyChanged::channelFor($company->id),
            'event' => 'company.changed',
        ]]);
    }
}
