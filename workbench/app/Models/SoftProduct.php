<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftProduct extends Product
{
    use SoftDeletes;

    protected $table = 'products';
}
