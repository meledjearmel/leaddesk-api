<?php

namespace App\Http\Middleware;

use App\Enums\ActorType;
use App\Enums\LifecycleStatus;
use App\Models\User;
use App\Support\ActingAgent;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifie l'agent au nom duquel la requête agit (en-tête X-LeadDesk-Agent).
 *
 * L'agent doit appartenir à l'entreprise du compte et être en service. Tout ce
 * qu'il fait ou consulte est alors journalisé à son nom (RM-34).
 */
class ResolveActingAgent
{
    public function __construct(
        private readonly ActingAgent $actingAgent,
        private readonly Audit $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Jamais d'agent hérité d'une requête précédente.
        $this->actingAgent->clear();

        $header = $request->header(ActingAgent::HEADER);

        if ($header === null || $header === '') {
            return $next($request);
        }

        $user = $request->user();
        $company = $user instanceof User ? $user->company : null;
        $agent = $company !== null && ctype_digit($header)
            ? $company->agents()->whereKey((int) $header)->first()
            : null;

        abort_if($agent === null, Response::HTTP_FORBIDDEN, "Cet agent n'appartient pas à votre entreprise.");
        abort_if(
            $agent->status !== LifecycleStatus::Active,
            Response::HTTP_FORBIDDEN,
            "Cet agent n'est pas en service : il ne peut pas agir.",
        );

        $this->actingAgent->set($agent);

        return $this->audit->actingAs(ActorType::Agent, $agent->id, fn (): Response => $next($request));
    }
}
