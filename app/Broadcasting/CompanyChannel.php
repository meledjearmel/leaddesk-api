<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Canal privé d'une entreprise : seul son CEO peut s'y abonner.
 */
class CompanyChannel
{
    public function join(User $user, int|string $companyId): bool
    {
        return $user->company?->id === (int) $companyId;
    }
}
