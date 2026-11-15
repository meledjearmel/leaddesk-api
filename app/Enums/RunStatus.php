<?php

namespace App\Enums;

/**
 * État d'une exécution de tâche.
 */
enum RunStatus: string
{
    case Running = 'running';

    /** Mise en pause par le CEO. */
    case Paused = 'paused';

    /** Mise en pause automatique : limite de coût atteinte (RM-36). */
    case PausedCost = 'paused_cost';

    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Vrai tant que l'exécution n'est pas terminée.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Running, self::Paused, self::PausedCost], true);
    }
}
