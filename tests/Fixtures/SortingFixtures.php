<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use Workbench\App\Models\Product;

final class SortingFixtures
{
    public static function seed(): void
    {
        foreach ([30, null, 10, 20, null, null] as $index => $quantity) {
            Product::query()->create([
                'id' => $index + 1,
                'name' => 'Product',
                'status' => 'open',
                'quantity' => $quantity,
                'deleted_at' => $index === 1 ? '2026-01-01' : null,
                'meta' => match ($index) {
                    0 => ['value' => 'charlie'], 2 => ['value' => 'alpha'], 3 => [
                        'value' => 'bravo',
                    ], 4 => [
                        'value' => null,
                    ], 5 => null, default => []
                },
            ]);
        }
    }
}
