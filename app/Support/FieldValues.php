<?php

namespace App\Support;

use App\Enums\FieldType;
use App\Models\ActivityType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valide les valeurs des champs propres à un type d'activité.
 *
 * Les règles viennent de la définition actuelle du type : rien n'est codé en
 * dur (RM-03), et une modification du type vaut pour les activités en cours
 * (RM-21).
 */
class FieldValues
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> Valeurs retenues : seulement celles des champs que le type définit.
     *
     * @throws ValidationException
     */
    public function validate(ActivityType $type, array $values): array
    {
        $rules = [];
        $labels = [];

        foreach ($type->fields ?? [] as $field) {
            $key = $field['key'];
            $labels["field_values.{$key}"] = $field['label'];
            $rules["field_values.{$key}"] = [
                $field['required'] ? 'required' : 'nullable',
                ...$this->typeRules(FieldType::from($field['type']), $field['options']),
            ];
        }

        /** @var array{field_values?: array<string, mixed>} $validated */
        $validated = Validator::make(['field_values' => $values], $rules, [], $labels)->validate();

        return $validated['field_values'] ?? [];
    }

    /**
     * @param  list<string>  $options
     * @return list<mixed>
     */
    private function typeRules(FieldType $type, array $options): array
    {
        return match ($type) {
            FieldType::Text => ['string', 'max:500'],
            FieldType::LongText => ['string', 'max:20000'],
            FieldType::Number => ['numeric'],
            FieldType::Date => ['date'],
            FieldType::Boolean => ['boolean'],
            FieldType::Select => ['string', Rule::in($options)],
        };
    }
}
