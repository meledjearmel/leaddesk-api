<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Journalise la création, la modification, la mise à la corbeille et la
 * restauration d'un modèle rattaché à une entreprise (RM-34).
 *
 * @mixin Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn (Model $model) => self::audit($model, 'created', $model->attributesToArray()));

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())->except(['updated_at', 'deleted_at'])->all();

            if ($changes !== []) {
                self::audit($model, 'updated', [
                    'before' => array_intersect_key($model->getRawOriginal(), $changes),
                    'after' => $changes,
                ]);
            }
        });

        static::deleted(fn (Model $model) => self::audit($model, 'deleted'));

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restored', fn (Model $model) => self::audit($model, 'restored'));
        }
    }

    /**
     * Identifiant de l'entreprise à laquelle rattacher l'entrée du journal.
     */
    public function auditCompanyId(): int
    {
        /** @var int $companyId */
        $companyId = $this->getAttribute('company_id');

        return $companyId;
    }

    /**
     * Préfixe des événements, par exemple « unit » pour « unit.created ».
     */
    public function auditName(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function audit(Model $model, string $verb, array $properties = []): void
    {
        /** @var self&Model $model */
        $hidden = array_flip($model->getHidden());

        app(Audit::class)->record(
            $model->auditCompanyId(),
            $model->auditName().'.'.$verb,
            $model,
            array_diff_key($properties, $hidden),
        );
    }
}
