<?php

namespace App\Enums;

/**
 * État d'un plan d'aménagement (D-035).
 */
enum OfficePlanStatus: string
{
    /** Proposé par un poste des Moyens généraux, en attente du CEO. */
    case Proposed = 'proposed';

    /** Plan en vigueur : un seul par entreprise. */
    case Accepted = 'accepted';

    case Rejected = 'rejected';

    /** Ancien plan en vigueur, remplacé par un plus récent. */
    case Superseded = 'superseded';

    /** Proposition dépassée : le plan a changé pendant qu'elle attendait (D-012). */
    case Stale = 'stale';
}
