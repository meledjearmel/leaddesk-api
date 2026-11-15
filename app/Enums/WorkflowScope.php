<?php

namespace App\Enums;

/**
 * Ce qu'un workflow fait avancer : des activités ou des tâches (RM-18).
 */
enum WorkflowScope: string
{
    case Activity = 'activity';
    case Task = 'task';
}
