<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Companies\CreateCompany;
use App\Enums\CompanyTemplate;
use App\Http\Resources\V1\CompanyResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Entreprise du compte connecté (RM-01, RM-02).
 */
#[Group('Entreprise', weight: 2)]
class CompanyController extends ApiController
{
    /**
     * Lire l'entreprise.
     *
     * Répond 404 tant que le compte n'a pas créé son entreprise.
     */
    public function show(Request $request): CompanyResource
    {
        return new CompanyResource($this->company($request));
    }

    /**
     * Créer l'entreprise.
     *
     * Un compte correspond à une seule entreprise (RM-01) : un second appel répond 409.
     * Une entreprise vierge est créée sans unité, poste ni agent (RM-02).
     */
    public function store(Request $request, CreateCompany $createCompany): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template' => ['required', Rule::enum(CompanyTemplate::class)],
        ]);

        $user = $this->user($request);

        // RM-01 : un compte correspond à une seule entreprise.
        if ($user->company()->exists()) {
            throw new ConflictHttpException('Ce compte a déjà une entreprise.');
        }

        $company = $createCompany->handle($user, $data['name'], CompanyTemplate::from($data['template']));

        return (new CompanyResource($company))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Renommer l'entreprise.
     */
    public function update(Request $request): CompanyResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $company = $this->company($request);
        $company->update($data);

        return new CompanyResource($company);
    }
}
