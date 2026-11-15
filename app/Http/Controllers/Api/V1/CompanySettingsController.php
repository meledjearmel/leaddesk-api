<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ActingAgent;
use App\Support\OfficeClock;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Réglages synchronisés de l'entreprise et horloge du bureau (RM-50, RM-51).
 */
#[Group('Entreprise', weight: 2)]
class CompanySettingsController extends ApiController
{
    /**
     * Lire les réglages et l'horloge du bureau.
     *
     * `settings` donne les valeurs effectives (celles de l'entreprise, sinon
     * les valeurs par défaut). `clock` donne le temps simulé au moment de la
     * réponse ; il se déduit du temps réel et est identique sur tous les
     * ordinateurs. Le temps simulé ne sert qu'à l'ambiance (RM-51).
     *
     * @return array{data: array{settings: array{simulated_day_minutes: int}, defaults: array{simulated_day_minutes: int}, clock: array{real_time: string, simulated_day: int, simulated_time: string, simulated_seconds: int, speed: float}}}
     */
    public function show(Request $request): array
    {
        return $this->payload($request);
    }

    /**
     * Modifier les réglages.
     *
     * Réservé au CEO : une requête au nom d'un agent répond 403.
     * `simulated_day_minutes` : durée réelle, en minutes, d'une journée
     * simulée de 24 h (RM-50). `null` revient à la valeur par défaut.
     *
     * @return array{data: array{settings: array{simulated_day_minutes: int}, defaults: array{simulated_day_minutes: int}, clock: array{real_time: string, simulated_day: int, simulated_time: string, simulated_seconds: int, speed: float}}}
     */
    public function update(Request $request, ActingAgent $actingAgent): array
    {
        if (! $actingAgent->isCeo()) {
            throw new AccessDeniedHttpException('Seul le CEO règle les paramètres de l\'entreprise.');
        }

        /** @var array{0: int, 1: int} $bounds */
        $bounds = config('leaddesk.simulated_day_minutes_bounds');

        $data = $request->validate([
            'simulated_day_minutes' => ['sometimes', 'nullable', 'integer', 'min:'.$bounds[0], 'max:'.$bounds[1]],
        ]);

        $company = $this->company($request);
        $settings = $company->settings ?? [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }
        }

        $company->update(['settings' => $settings === [] ? null : $settings]);

        return $this->payload($request);
    }

    /**
     * @return array{data: array{settings: array{simulated_day_minutes: int}, defaults: array{simulated_day_minutes: int}, clock: array{real_time: string, simulated_day: int, simulated_time: string, simulated_seconds: int, speed: float}}}
     */
    private function payload(Request $request): array
    {
        $company = $this->company($request);

        /** @var array{simulated_day_minutes: int} $defaults */
        $defaults = config('leaddesk.company_settings');

        return ['data' => [
            'settings' => OfficeClock::settings($company),
            'defaults' => $defaults,
            'clock' => OfficeClock::at($company),
        ]];
    }
}
