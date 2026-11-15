<?php

namespace App\Enums;

/**
 * Ce qu'une règle d'un niveau inférieur fait d'une règle héritée (RM-24).
 * Il n'existe volontairement aucune valeur pour l'assouplir ou la supprimer.
 */
enum RuleRelation: string
{
    /** Préciser la règle héritée. */
    case Precise = 'precise';

    /** Renforcer la règle héritée. */
    case Reinforce = 'reinforce';
}
