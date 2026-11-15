<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Override;

class ScopedProduct extends Product
{
    protected $table = 'products';

    #[Override]
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->qualifyColumn('tenant_id'), 1);
        });
    }
}
