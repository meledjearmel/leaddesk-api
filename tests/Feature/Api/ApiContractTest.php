<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le contrat docs/api/openapi.json est la source de vérité de l'application :
 * il doit toujours correspondre au code.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_committed_contract_matches_the_code(): void
    {
        // Le contrat est généré sur SQLite : les types déduits des colonnes
        // peuvent différer sur un autre moteur.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Le contrat est comparé sur SQLite uniquement.');
        }

        $fresh = storage_path('framework/testing/openapi.json');
        @mkdir(dirname($fresh), recursive: true);

        $this->artisan('scramble:export', ['--path' => $fresh]);

        $committed = $this->contract(base_path('docs/api/openapi.json'));
        $generated = $this->contract($fresh);
        @unlink($fresh);

        // Comparaison booléenne : la différence complète de deux contrats est illisible.
        $this->assertTrue(
            $committed === $generated,
            'Le contrat est périmé : lancer « php artisan scramble:export » et commiter docs/api/openapi.json.',
        );
    }

    public function test_the_contract_documents_only_the_versioned_api(): void
    {
        $contract = $this->contract(base_path('docs/api/openapi.json'));

        $this->assertArrayHasKey('/company', $contract['paths']);

        foreach (array_keys($contract['paths']) as $path) {
            $this->assertStringNotContainsString('prototypes', (string) $path);
        }
    }

    /**
     * Contrat décodé, sans l'adresse du serveur qui dépend de l'environnement.
     *
     * @return array<string, mixed>
     */
    private function contract(string $path): array
    {
        $this->assertFileExists($path);

        /** @var array<string, mixed> $contract */
        $contract = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unset($contract['servers']);

        return $contract;
    }
}
