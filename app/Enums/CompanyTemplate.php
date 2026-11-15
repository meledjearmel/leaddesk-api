<?php

namespace App\Enums;

/**
 * Configuration de départ d'une entreprise (RM-02).
 */
enum CompanyTemplate: string
{
    case Blank = 'blank';
    case Studio = 'studio';
}
