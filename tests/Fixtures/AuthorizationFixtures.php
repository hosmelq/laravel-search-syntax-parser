<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use Workbench\App\Models\Product;

final class AuthorizationFixtures
{
    public static function seed(): void
    {
        Product::query()->create([
            'id' => 1,
            'name' => 'Coffee',
            'status' => 'open',
            'quantity' => 10,
        ]);
        Product::query()->create([
            'id' => 2,
            'name' => 'Ceramic',
            'status' => 'closed',
            'quantity' => 20,
        ]);
    }
}
