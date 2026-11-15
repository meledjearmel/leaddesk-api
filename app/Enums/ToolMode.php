<?php

namespace App\Enums;

/**
 * Ce qu'un poste peut faire d'un outil local pendant l'exécution.
 */
enum ToolMode: string
{
    /** Autorisé d'office. */
    case Allow = 'allow';

    /** Soumis à l'approbation du CEO, à chaque usage. */
    case Ask = 'ask';

    /** Interdit. */
    case Deny = 'deny';
}
