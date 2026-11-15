<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\OfficeContextTool;
use App\Mcp\Tools\OfficeProposePlanTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP interne de LeadDesk (D-038) : les agents de l'entreprise s'en servent
 * depuis l'application de bureau, avec le jeton du CEO et l'en-tête
 * `X-LeadDesk-Agent`. Tout ce qu'ils font est journalisé à leur nom (RM-34).
 */
#[Name('LeadDesk')]
#[Version('0.1.0')]
#[Instructions(<<<'TEXT'
MCP interne de LeadDesk, pour les agents d'une entreprise. Tu agis au nom d'un agent, dans les limites de son poste ; le CEO décide.

Aménagement du bureau (poste des Moyens généraux) :
1. office.context donne les départements et leur effectif, le catalogue des salles, le plan en vigueur et les règles.
2. Construis un plan qui respecte toutes les règles. Pars du plan en vigueur et garde l'identifiant des salles qui restent.
3. office.propose_plan l'envoie avec un motif clair. Un plan invalide est refusé avec la liste des problèmes : corrige-les et propose à nouveau.
4. La proposition attend le CEO. Ne propose pas un autre plan tant qu'une proposition attend, sauf si l'organisation a changé.
TEXT)]
class LeadDeskServer extends Server
{
    /** @var array<int, class-string<Server\Tool>> */
    protected array $tools = [
        OfficeContextTool::class,
        OfficeProposePlanTool::class,
    ];
}
