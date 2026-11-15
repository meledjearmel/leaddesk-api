<?php

use App\Http\Middleware\ResolveActingAgent;
use App\Mcp\Servers\LeadDeskServer;
use Laravel\Mcp\Facades\Mcp;

// MCP interne (D-038) : jeton Sanctum du CEO, agent dans l'en-tête X-LeadDesk-Agent.
Mcp::web('/mcp/leaddesk', LeadDeskServer::class)->middleware(['auth:sanctum', ResolveActingAgent::class]);
