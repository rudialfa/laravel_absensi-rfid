<?php

namespace App\Models\Scopes;

use App\Support\SchoolContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = SchoolContext::id();

        if ($id !== null) {
            $builder->where($model->qualifyColumn('school_id'), $id);

            return;
        }

        // HTTP tanpa sekolah: tertutup. Konsol dan job tidak disaring.
        if (SchoolContext::failClosed() && $model->schoolScopeStrict()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
