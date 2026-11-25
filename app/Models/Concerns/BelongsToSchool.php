<?php

namespace App\Models\Concerns;

use App\Models\School;
use App\Models\Scopes\SchoolScope;
use App\Support\SchoolContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Model yang dimiliki satu sekolah: disaring otomatis per sekolah aktif, dan
 * school_id terisi otomatis saat data dibuat.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope());

        static::creating(function (Model $model) {
            if ($model->getAttribute('school_id') !== null || !$model->needsSchool()) {
                return;
            }

            $id = SchoolContext::idForNewRecord();

            if ($id === null) {
                throw new LogicException('Sekolah belum ditetapkan, data baru ditolak.');
            }

            $model->setAttribute('school_id', $id);
        });
    }

    /** Model boleh menimpa, misalnya User super_admin yang tanpa sekolah. */
    public function needsSchool(): bool
    {
        return true;
    }

    /** False = tidak ditutup saat HTTP tanpa sekolah (hanya User, untuk login dan token). */
    public function schoolScopeStrict(): bool
    {
        return true;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
