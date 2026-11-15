<?php

namespace App\Actions\Companies;

use App\Enums\CompanyTemplate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Crée l'entreprise d'un compte à partir d'une configuration de départ (RM-02).
 */
class CreateCompany
{
    public function handle(User $user, string $name, CompanyTemplate $template): Company
    {
        return DB::transaction(function () use ($user, $name, $template): Company {
            // Une entreprise vierge existe sans unité, poste ni type d'activité.
            return Company::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'template' => $template,
            ]);
        });
    }
}
