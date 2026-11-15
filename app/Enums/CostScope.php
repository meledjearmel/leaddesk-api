<?php

namespace App\Enums;

/**
 * Niveau auquel une limite de coût s'applique.
 */
enum CostScope: string
{
    case Company = 'company';
    case Project = 'project';
    case Task = 'task';
}
