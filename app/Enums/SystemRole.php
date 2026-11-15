<?php

namespace App\Enums;

/**
 * Rôle système d'une unité : présente dans toute entreprise et impossible à
 * supprimer (D-013). Le code s'appuie sur ce rôle, jamais sur le nom de
 * l'unité, que le CEO peut changer (RM-03).
 */
enum SystemRole: string
{
    case Hr = 'hr';
    case Facilities = 'facilities';

    /** Nom donné à l'unité quand elle est créée d'office. */
    public function defaultName(): string
    {
        return match ($this) {
            self::Hr => 'Ressources humaines',
            self::Facilities => 'Moyens généraux',
        };
    }

    public function defaultColor(): string
    {
        return match ($this) {
            self::Hr => '#B58BD9',
            self::Facilities => '#8C9A5B',
        };
    }

    public function headTitle(): string
    {
        return match ($this) {
            self::Hr => 'Responsable Ressources humaines',
            self::Facilities => 'Responsable Moyens généraux',
        };
    }

    public function headMission(): string
    {
        return match ($this) {
            self::Hr => 'Préparer les recrutements et suivre l\'évolution des agents.',
            self::Facilities => 'Aménager le bureau et proposer au CEO les réaménagements nécessaires.',
        };
    }
}
