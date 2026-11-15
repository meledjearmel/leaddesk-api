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
    public function __construct(private readonly RoomCatalog $catalog) {}

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
                || $room['x'] + $room['cols'] > $layout['cols']
                || $room['y'] + $room['rows'] > $layout['rows']) {
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
