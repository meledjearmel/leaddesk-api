<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base des contrôleurs de l'API /api/v1.
 */
abstract class ApiController extends Controller
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Entreprise du compte connecté. Toutes les données métier en dépendent :
     * aucune ressource d'une autre entreprise n'est jamais atteignable.
     */
    protected function company(Request $request): Company
    {
        $company = $this->user($request)->company;

        if ($company === null) {
            throw new NotFoundHttpException("Ce compte n'a pas encore d'entreprise.");
        }

        return $company;
    }
}
