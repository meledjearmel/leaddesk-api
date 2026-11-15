<?php

namespace App\Enums;

/**
 * Nature d'une entrée du journal (RM-34) : consultation, action ou changement.
 */
enum AuditKind: string
{
    case Consultation = 'consultation';
    case Action = 'action';
    case Change = 'change';
}
