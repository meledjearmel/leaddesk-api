<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Position;
use App\Models\Unit;
use App\Office\OfficeLayoutWatcher;
use App\Support\ActingAgent;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un seul journal par requête : il porte l'auteur courant (RM-34).
        $this->app->scoped(Audit::class);
        // L'agent au nom duquel la requête agit (en-tête X-LeadDesk-Agent).
        $this->app->scoped(ActingAgent::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiDocs();
        $this->watchOfficeLayout();
    }

    /**
     * D-039 : un changement d'organisation qui ne tient plus dans le plan
     * du bureau déclenche une demande de réaménagement, une fois la
     * transaction validée.
     */
    protected function watchOfficeLayout(): void
    {
        foreach ([Unit::class, Position::class] as $model) {
            foreach (['saved', 'deleted', 'restored'] as $event) {
                $model::registerModelEvent($event, function (Unit|Position $changed): void {
                    $companyId = $changed->company_id;

                    DB::afterCommit(function () use ($companyId): void {
                        if ($company = Company::query()->find($companyId)) {
                            app(OfficeLayoutWatcher::class)->check($company);
                        }
                    });
                });
            }
        }
    }

    /**
     * Documentation OpenAPI générée par Scramble : toutes les routes demandent
     * un jeton, sauf celles qui déclarent le contraire.
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
