<?php

namespace App\Support;

use App\Enums\LifecycleStatus;
use App\Models\Agent;
use App\Models\Company;
use App\Models\Position;

/**
 * Réglages synchronisés d'une entreprise : valeurs choisies par le CEO,
 * sinon valeurs par défaut de `config/leaddesk.php` (rien en dur, RM-03).
 */
class CompanySettings
{
    /**
     * @return array{simulated_day_minutes: int, mission_position_id: int|null}
     */
    public static function defaults(): array
    {
        /** @var array{simulated_day_minutes: int, mission_position_id: int|null} $defaults */
        $defaults = config('leaddesk.company_settings');

        return $defaults;
    }

    /**
     * @return array{simulated_day_minutes: int, mission_position_id: int|null}
     */
    public static function for(Company $company): array
    {
        $defaults = self::defaults();

        /** @var array{simulated_day_minutes: int, mission_position_id: int|null} $settings */
        $settings = array_replace($defaults, array_intersect_key($company->settings ?? [], $defaults));

        return $settings;
    }

    /**
     * Poste qui reçoit les missions du CEO (le « CEO virtuel », D-017) :
     * le poste réglé, sinon le premier poste de responsable qui ne rend
     * compte à aucun autre poste.
     */
    public static function missionPosition(Company $company): ?Position
    {
        $id = self::for($company)['mission_position_id'];

        if ($id !== null) {
            $position = $company->positions()->find($id);

            if ($position !== null) {
                return $position;
            }
        }

        return $company->positions()
            ->where('is_head', true)
            ->whereNull('reports_to_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Agent en service qui occupe le poste des missions.
     */
    public static function missionAgent(Company $company): ?Agent
    {
        $position = self::missionPosition($company);

        if ($position === null) {
            return null;
        }

        return $company->agents()
            ->where('position_id', $position->id)
            ->where('status', LifecycleStatus::Active)
            ->orderBy('id')
            ->first();
    }
}
