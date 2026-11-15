<?php

namespace App\Enums;

/**
 * Nature d'un champ configurable d'un type d'activité.
 */
enum FieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';

    /** Choix dans une liste d'options. */
    case Select = 'select';
}
