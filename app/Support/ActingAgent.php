<?php

namespace App\Support;

use App\Models\Agent;

/**
 * Agent au nom duquel la requête en cours agit, s'il y en a un.
 *
 * Les agents s'exécutent dans l'application de bureau du CEO : elle appelle
 * l'API avec le jeton du CEO et l'en-tête `X-LeadDesk-Agent`. Sans en-tête,
 * c'est le CEO lui-même qui agit.
 */
class ActingAgent
{
    public const string HEADER = 'X-LeadDesk-Agent';

    private ?Agent $agent = null;

    public function set(Agent $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * Oublie l'agent : à chaque nouvelle requête, c'est le CEO qui agit tant
     * que l'en-tête ne dit pas le contraire.
     */
    public function clear(): void
    {
        $this->agent = null;
    }

    public function get(): ?Agent
    {
        return $this->agent;
    }

    /**
     * Vrai quand le CEO humain agit lui-même.
     */
    public function isCeo(): bool
    {
        return $this->agent === null;
    }
}
