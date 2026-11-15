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
    public function __construct(private readonly ApplyCompanyTemplate $applyTemplate) {}

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

            return $company;
        });
    }
}
