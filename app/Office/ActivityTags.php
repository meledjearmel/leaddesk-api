<?php

namespace App\Office;

/**
 * Étiquettes d'activité reconnues par le catalogue des salles (D-037).
 *
 * Donnée partagée avec l'application : `resources/office/activity-tags.json`
 * reprend `ActivityTag.all` de `room_catalog.dart`. Une unité porte les
 * étiquettes de ses activités ; aucune salle ne nomme un département (RM-03).
 */
class ActivityTags
{
    /** @var list<string>|null */
    private static ?array $all = null;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        if (self::$all === null) {
            $raw = file_get_contents(resource_path('office/activity-tags.json'));
            /** @var list<string> $tags */
            $tags = $raw === false ? [] : json_decode($raw, true);
            self::$all = $tags;
        }

        return self::$all;
    }
}
