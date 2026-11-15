<?php

namespace App\Support;

use App\Enums\ToolMode;
use App\Models\Position;

/**
 * Permissions d'exécution d'un poste, outil par outil.
 *
 * Le catalogue des outils et le mode par défaut sont des réglages
 * (config/leaddesk.php) : rien n'est codé en dur (RM-03).
 */
class ToolPermissions
{
    /**
     * @return array<string, string> Libellé de chaque outil du catalogue, par identifiant.
     */
    public function catalog(): array
    {
        /** @var array<string, string> $tools */
        $tools = config('leaddesk.tools');

        return $tools;
    }

    public function defaultMode(): ToolMode
    {
        /** @var string $mode */
        $mode = config('leaddesk.default_tool_mode');

        return ToolMode::from($mode);
    }

    /**
     * Mode de chaque outil du catalogue pour ce poste. Un outil que le poste
     * ne règle pas prend le mode par défaut ; sans poste, tout est interdit.
     *
     * @return array<string, string>
     */
    public function resolve(?Position $position): array
    {
        $resolved = [];

        foreach (array_keys($this->catalog()) as $tool) {
            $resolved[$tool] = $this->modeFor($position, $tool)->value;
        }

        return $resolved;
    }

    public function modeFor(?Position $position, string $tool): ToolMode
    {
        if ($position === null || ! array_key_exists($tool, $this->catalog())) {
            return ToolMode::Deny;
        }

        $configured = $position->tool_permissions[$tool] ?? null;

        return $configured === null
            ? $this->defaultMode()
            : (ToolMode::tryFrom($configured) ?? ToolMode::Deny);
    }
}
