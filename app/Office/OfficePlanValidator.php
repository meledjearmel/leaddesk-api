<?php

namespace App\Office;

/**
 * Règles d'un plan d'aménagement, identiques à `OfficePlan.validate` de
 * l'application (D-010) : mêmes contrôles, même ordre, mêmes messages. Les cas
 * de `resources/office/validation-cases.json` sont joués des deux côtés.
 *
 * Le plan suit le format `OfficePlan.toJson()` ; sa forme (types des champs)
 * est vérifiée avant, par la validation de la requête.
 *
 * @phpstan-type PlacedRoom array{id: string, type: string, department?: string|null, name?: string|null, x: int, y: int, cols: int, rows: int}
 * @phpstan-type Layout array{version?: int, cols: int, rows: int, rooms: list<PlacedRoom>}
 */
class OfficePlanValidator
{
    public const int MAX_SIDE = 200;

    public const int MAX_ROOMS = 300;

    public function __construct(private readonly RoomCatalog $catalog) {}

    /**
     * Règles de forme d'un plan, pour la validation d'une requête.
     *
     * @return array<string, list<string>>
     */
    public static function shapeRules(string $prefix = 'layout'): array
    {
        $side = 'between:1,'.self::MAX_SIDE;

        return [
            $prefix => ['required', 'array'],
            "{$prefix}.version" => ['sometimes', 'integer', 'in:1'],
            "{$prefix}.cols" => ['required', 'integer', $side],
            "{$prefix}.rows" => ['required', 'integer', $side],
            "{$prefix}.rooms" => ['present', 'array', 'list', 'max:'.self::MAX_ROOMS],
            "{$prefix}.rooms.*" => ['array'],
            "{$prefix}.rooms.*.id" => ['required', 'string', 'max:64'],
            "{$prefix}.rooms.*.type" => ['required', 'string', 'max:64'],
            "{$prefix}.rooms.*.department" => ['sometimes', 'nullable', 'string', 'max:64'],
            "{$prefix}.rooms.*.name" => ['sometimes', 'nullable', 'string', 'max:80'],
            "{$prefix}.rooms.*.x" => ['required', 'integer', 'between:-'.self::MAX_SIDE.','.self::MAX_SIDE],
            "{$prefix}.rooms.*.y" => ['required', 'integer', 'between:-'.self::MAX_SIDE.','.self::MAX_SIDE],
            "{$prefix}.rooms.*.cols" => ['required', 'integer', $side],
            "{$prefix}.rooms.*.rows" => ['required', 'integer', $side],
        ];
    }

    /**
     * Plan nettoyé : seuls les champs connus, dans l'ordre de `toJson()`.
     *
     * @param  array<string, mixed>  $input
     * @return Layout
     */
    public static function normalize(array $input): array
    {
        /** @var list<array<string, mixed>> $rooms */
        $rooms = $input['rooms'];

        return [
            'version' => 1,
            'cols' => (int) $input['cols'],
            'rows' => (int) $input['rows'],
            'rooms' => array_map(fn (array $room): array => array_filter([
                'id' => (string) $room['id'],
                'type' => (string) $room['type'],
                'department' => isset($room['department']) ? (string) $room['department'] : null,
                'name' => isset($room['name']) ? (string) $room['name'] : null,
                'x' => (int) $room['x'],
                'y' => (int) $room['y'],
                'cols' => (int) $room['cols'],
                'rows' => (int) $room['rows'],
            ], fn (mixed $value): bool => $value !== null), $rooms),
        ];
    }

    /**
     * Problèmes du plan, en français ; liste vide si le plan est valable.
     *
     * @param  Layout  $layout
     * @param  list<OfficeDepartment>  $departments
     * @return list<string>
     */
    public function validate(array $layout, array $departments): array
    {
        $problems = [];
        $known = [];
        foreach ($departments as $department) {
            $known[$department->id] = $department;
        }
        $ids = [];
        $rooms = $layout['rooms'];

        foreach ($rooms as $room) {
            $type = $this->catalog->find($room['type']);
            $label = $this->label($room);

            if (isset($ids[$room['id']])) {
                $problems[] = "La salle « {$room['id']} » apparaît deux fois.";
            }
            $ids[$room['id']] = true;

            if ($type === null) {
                $problems[] = "Type de salle inconnu : « {$room['type']} ».";

                continue;
            }
            if ($room['x'] < 0 || $room['y'] < 0
                || $layout['cols'] < $room['x'] + $room['cols']
                || $layout['rows'] < $room['y'] + $room['rows']) {
                $problems[] = "{$label} dépasse du plan.";
            }
            if ($room['cols'] < $type['min_cols'] || $room['rows'] < $type['min_rows']) {
                $problems[] = "{$label} est trop petite ({$type['min_cols']} × {$type['min_rows']} cases au minimum).";
            }

            $owner = $room['department'] ?? null;
            if ($type['scope'] === RoomCatalog::SCOPE_SHARED && $owner !== null) {
                $problems[] = "{$label} est un lieu commun : pas de département.";
            }
            if ($type['scope'] === RoomCatalog::SCOPE_DEPARTMENT) {
                if ($owner === null) {
                    $problems[] = "{$label} doit appartenir à un département.";
                } elseif (! isset($known[$owner])) {
                    $problems[] = "{$label} : département inconnu « {$owner} ».";
                }
            }
        }

        $count = count($rooms);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->overlaps($rooms[$i], $rooms[$j])) {
                    $problems[] = "{$this->label($rooms[$i])} et {$this->label($rooms[$j])} se chevauchent : deux départements ne partagent jamais une salle.";
                }
            }
        }

        foreach ($departments as $department) {
            $seats = 0;
            foreach ($rooms as $room) {
                $type = $this->catalog->find($room['type']);
                if ($type !== null && ($room['department'] ?? null) === $department->id) {
                    $seats += RoomCatalog::seatsFor($type, $room['rows']);
                }
            }

            if ($department->headcount > 0 && $seats === 0) {
                $problems[] = "{$department->name} n'a aucun poste de travail.";
            } elseif ($seats < $department->headcount) {
                $problems[] = "{$department->name} : {$seats} postes pour {$department->headcount} personnes.";
            }
        }

        return $problems;
    }

    /**
     * @param  PlacedRoom  $room
     */
    private function label(array $room): string
    {
        return $room['name'] ?? $this->catalog->find($room['type'])['name'] ?? $room['type'];
    }

    /**
     * @param  PlacedRoom  $a
     * @param  PlacedRoom  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['cols'] && $b['x'] < $a['x'] + $a['cols']
            && $a['y'] < $b['y'] + $b['rows'] && $b['y'] < $a['y'] + $a['rows'];
    }
}
