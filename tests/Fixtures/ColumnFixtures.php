<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use Closure;
use Workbench\App\Models\Product;

final class ColumnFixtures
{
    public static function ids(null|string $input, null|Closure $configure = null): array
    {
        return Product::query()
            ->where('tenant_id', 1)
            ->whereSearch($input, $configure)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function seed(): void
    {
        $rows = [
            [
                1,
                1,
                'Ana Silva',
                'open',
                'Ana',
                'Silva',
                'Red coffee mug',
                0,
                1_050,
                4.5,
                '550e8400-e29b-41d4-a716-446655440001',
            ],
            [
                2,
                1,
                'Bob Stone',
                'pending',
                'Bob',
                null,
                null,
                10,
                2_000,
                3.25,
                '550e8400-e29b-41d4-a716-446655440002',
            ],
            [
                3,
                1,
                'Carla Diaz',
                'closed',
                null,
                'Diaz',
                'Tea _100% ! special',
                20,
                500,
                null,
                null,
            ],
            [
                4,
                1,
                'Ana Bell',
                'open',
                'Ana',
                'Bell',
                'Blue coffee',
                null,
                null,
                0,
                '550e8400-e29b-41d4-a716-446655440003',
            ],
            [5, 1, 'Plain', 'pending', null, null, 'a%b a_b a!b a\\b', 30, 3_000, 1.5, null],
            [
                6,
                2,
                'Outside',
                'open',
                'Ana',
                'Silva',
                'coffee',
                10,
                1_000,
                5,
                '550e8400-e29b-41d4-a716-446655440004',
            ],
        ];

        foreach ($rows as $row) {
            Product::query()->create(array_combine([
                'id', 'tenant_id', 'name', 'status', 'first_name', 'last_name', 'description',
                'quantity', 'price_cents', 'rating', 'uuid',
            ], $row));
        }
    }
}
