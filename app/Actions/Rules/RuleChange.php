<?php

namespace App\Actions\Rules;

use App\Enums\RuleRelation;
use App\Models\Company;
use App\Models\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * Valide puis applique un changement de règle : création, modification ou
 * retrait. Le même code sert au CEO qui agit directement et à l'approbation
 * d'une proposition (RM-25).
 *
 * @phpstan-type Change array{operation: string, rule_id?: int|null, unit_id?: int|null, parent_rule_id?: int|null, relation?: string|null, title?: string|null, body?: string|null}
 */
class RuleChange
{
    public const array OPERATIONS = ['create', 'update', 'archive'];

    /**
     * Vérifie un changement contre l'état actuel de l'entreprise.
     *
     * @param  array<string, mixed>  $input
     * @return Change
     *
     * @throws ValidationException
     */
    public function validate(Company $company, array $input): array
    {
        $sameCompany = fn (string $table) => ValidationRule::exists($table, 'id')
            ->where('company_id', $company->id)
            ->whereNull('deleted_at');

        /** @var Change $change */
        $change = Validator::make($input, [
            'operation' => ['required', ValidationRule::in(self::OPERATIONS)],
            // Règle visée par une modification ou un retrait.
            'rule_id' => ['required_unless:operation,create', 'nullable', 'integer', $sameCompany('rules')],
            // Niveau d'une nouvelle règle : vide pour toute l'entreprise.
            'unit_id' => ['sometimes', 'nullable', 'integer', $sameCompany('units')],
            // Règle héritée qu'une nouvelle règle précise ou renforce.
            'parent_rule_id' => ['sometimes', 'nullable', 'integer', $sameCompany('rules')],
            'relation' => ['required_with:parent_rule_id', 'nullable', ValidationRule::enum(RuleRelation::class)],
            'title' => ['required_if:operation,create', 'nullable', 'string', 'max:200'],
            'body' => ['required_if:operation,create', 'nullable', 'string', 'max:20000'],
        ])->validate();

        if ($change['operation'] === 'create' && isset($change['parent_rule_id'])) {
            $this->ensureParentIsInherited($company, $change);
        }

        return $change;
    }

    /**
     * Applique un changement déjà validé.
     *
     * RM-27 : la règle vaut pour les activités créées à partir de maintenant ;
     * elle ne touche les activités en cours que si $extendToRunning est vrai.
     *
     * @param  Change  $change
     */
    public function apply(Company $company, array $change, bool $extendToRunning = false): ?Rule
    {
        if ($change['operation'] === 'create') {
            return $company->rules()->create([
                'unit_id' => $change['unit_id'] ?? null,
                'parent_rule_id' => $change['parent_rule_id'] ?? null,
                'relation' => isset($change['parent_rule_id']) ? ($change['relation'] ?? null) : null,
                'title' => $change['title'] ?? '',
                'body' => $change['body'] ?? '',
                'effective_at' => now(),
                'extended_to_running' => $extendToRunning,
            ]);
        }

        $rule = $company->rules()->whereKey($change['rule_id'] ?? 0)->firstOrFail();

        if ($change['operation'] === 'archive') {
            $rule->delete();

            return null;
        }

        $rule->update([
            'title' => $change['title'] ?? $rule->title,
            'body' => $change['body'] ?? $rule->body,
            'version' => $rule->version + 1,
            'effective_at' => now(),
            'extended_to_running' => $extendToRunning,
        ]);

        return $rule;
    }

    /**
     * RM-24 : un niveau inférieur précise ou renforce une règle héritée. La
     * règle visée doit donc venir d'un niveau strictement supérieur.
     *
     * @param  Change  $change
     */
    private function ensureParentIsInherited(Company $company, array $change): void
    {
        $parent = $company->rules()->whereKey($change['parent_rule_id'] ?? 0)->firstOrFail();
        $unit = isset($change['unit_id'])
            ? $company->units()->whereKey($change['unit_id'])->firstOrFail()
            : null;

        $inherited = $unit !== null
            && $parent->unit_id !== $unit->id
            && $parent->appliesToUnit($unit);

        if (! $inherited) {
            throw ValidationException::withMessages([
                'parent_rule_id' => ["Une règle ne précise ou ne renforce qu'une règle héritée d'un niveau supérieur."],
            ]);
        }
    }
}
