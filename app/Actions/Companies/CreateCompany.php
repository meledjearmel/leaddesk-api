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
    public function __construct(
        private readonly ApplyCompanyTemplate $applyTemplate,
        private readonly EnsureSystemUnits $ensureSystemUnits,
    ) {}

    public function handle(User $user, string $name, CompanyTemplate $template): Company
    {
        // Tout ou rien : une entreprise n'existe jamais avec un modèle à moitié copié.
        return DB::transaction(function () use ($user, $name, $template): Company {
            $company = Company::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'template' => $template,
            ]);

            $this->applyTemplate->handle($company, $template);

            // D-013 : RH et Moyens généraux existent dans toute entreprise.
            $this->ensureSystemUnits->handle($company);

            return $company;
        });
    }
}
