<?php

namespace App\Enums;

/**
 * Sens d'une étape de workflow. Le libellé de l'étape est libre ; la catégorie
 * permet au logiciel de savoir où en est le travail.
 */
enum StepCategory: string
{
    /** À faire. */
    case Todo = 'todo';

    /** En cours. */
    case InProgress = 'in_progress';

    /** Terminé par ses auteurs, en attente de confirmation. */
    case Review = 'review';

    /** Terminé et confirmé. */
    case Done = 'done';

    /** Abandonné. */
    case Cancelled = 'cancelled';

    /**
     * Vrai quand le travail n'avance plus : terminé ou abandonné.
     */
    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}
