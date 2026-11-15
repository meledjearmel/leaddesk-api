<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Horloge du bureau vivant (RM-50, RM-51).
 *
 * Le temps simulé se déduit du temps réel et du réglage de l'entreprise : il
 * est le même sur tous les ordinateurs, sans rien stocker. Il ne sert qu'à
 * l'ambiance : échéances, historique et coûts restent en temps réel.
 */
class OfficeClock
{
    public const SECONDS_PER_DAY = 86400;

    /**
     * @return array{real_time: string, simulated_day: int, simulated_time: string, simulated_seconds: int, speed: float}
     */
    public static function at(Company $company, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $dayMinutes = CompanySettings::for($company)['simulated_day_minutes'];
        $speed = 1440 / $dayMinutes;

        // Secondes simulées écoulées depuis l'époque Unix : identiques partout.
        $simulated = (int) floor($now->getTimestamp() * $speed);
        $secondsOfDay = $simulated % self::SECONDS_PER_DAY;

        return [
            'real_time' => $now->toIso8601String(),
            'simulated_day' => intdiv($simulated, self::SECONDS_PER_DAY),
            'simulated_time' => sprintf('%02d:%02d:%02d', intdiv($secondsOfDay, 3600), intdiv($secondsOfDay % 3600, 60), $secondsOfDay % 60),
            'simulated_seconds' => $secondsOfDay,
            'speed' => $speed,
        ];
    }
}
