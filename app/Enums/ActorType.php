<?php

namespace App\Enums;

/**
 * Auteur d'une entrée du journal de traçabilité.
 */
enum ActorType: string
{
    case Ceo = 'ceo';
    case Agent = 'agent';
    case System = 'system';
}
