<?php

namespace App\Office;

use RuntimeException;

/**
 * Catalogue des types de salle du bureau (D-035).
 *
 * Donnée partagée avec l'application de bureau : `resources/office/room-types.json`
 * reprend exactement `lib/office/room_catalog.dart` (un test de l'application
 * compare les deux). Les salles ne nomment aucun département : elles servent
 * des activités (RM-03).
 *
 * @phpstan-type RoomType array{id: string, name: string, category: string, scope: string, min_cols: int, min_rows: int, seats_per_row: int, tags: list<string>, proposed: bool}
 */
class RoomCatalog
{
    public const string SCOPE_DEPARTMENT = 'department';

    public const string SCOPE_SHARED = 'shared';

    /** @var array<string, RoomType>|null */
    private ?array $types = null;

    public static function path(): string
    {
        return resource_path('office/room-types.json');
    }

    /**
     * @return list<RoomType>
     */
    public function all(): array
    {
        return array_values($this->types());
    }

    /**
     * @return RoomType|null
     */
    public function find(string $id): ?array
    {
        return $this->types()[$id] ?? null;
    }

    /**
     * Postes de travail d'une salle de $rows rangées de cases.
     *
     * @param  RoomType  $type
     */
    public static function seatsFor(array $type, int $rows): int
    {
        return $type['seats_per_row'] * intdiv(max($rows, 0), 2);
    }

    /**
     * @return array<string, RoomType>
     */
    private function types(): array
    {
        if ($this->types === null) {
            $raw = file_get_contents(self::path());
            /** @var list<RoomType>|null $list */
            $list = $raw === false ? null : json_decode($raw, true);

            if (! is_array($list)) {
                throw new RuntimeException('Catalogue des salles illisible.');
            }

            $this->types = [];
            foreach ($list as $type) {
                $this->types[$type['id']] = $type;
            }
        }

        return $this->types;
    }
}
