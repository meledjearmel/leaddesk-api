<?php

namespace App\Enums;

/**
 * État d'une demande soumise à la décision du CEO.
 */
enum DecisionStatus: string
{
    /** En attente de la décision du CEO. */
    case Pending = 'pending';

    /** Le CEO a demandé des précisions. */
    case NeedsInfo = 'needs_info';

    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Vrai tant que le CEO n'a ni approuvé ni refusé.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::NeedsInfo;
    }
}
