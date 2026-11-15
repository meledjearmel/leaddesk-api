<?php

namespace App\Enums;

/**
 * État d'un poste ou d'un agent : en brouillon ou en service.
 */
enum LifecycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
}
