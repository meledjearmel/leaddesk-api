<?php

namespace App\Mcp\Concerns;

use App\Models\Company;
use App\Models\User;
use Laravel\Mcp\Request;
use RuntimeException;

/**
 * Entreprise du compte connecté au MCP : aucun outil n'atteint une autre
 * entreprise.
 */
trait ResolvesCompany
{
    protected function company(Request $request): Company
    {
        $user = $request->user();
        $company = $user instanceof User ? $user->company : null;

        if ($company === null) {
            throw new RuntimeException("Ce compte n'a pas encore d'entreprise.");
        }

        return $company;
    }
}
