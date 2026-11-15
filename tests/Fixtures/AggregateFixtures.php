<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Workbench\App\Models\Line;
use Workbench\App\Models\Product;

final class AggregateFixtures
{
    public static function ids(string $input, Field $field): array
    {
        return Product::query()
            ->where('tenant_id', 1)
            ->tap(Search::make()->fields([$field])->timezone('America/Managua')->query($input))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function seed(): void
    {
        config()->set('app.timezone', 'UTC');

        foreach (range(1, 8) as $id) {
            Product::query()->create([
                'id' => $id,
                'name' => 'Product '.$id,
                'status' => 'open',
                'tenant_id' => $id === 8 ? 2 : 1,
            ]);
        }

        foreach ([
            [1, 10, '2025-01-14 06:00:00.000001'],
            [1, 20, '2025-01-15 06:00:00.000001'],
            [2, 5, '2025-01-15 06:00:00.000002'],
            [3, null, null],
            [6, 0, '2025-01-16 06:00:00.000000'],
            [7, 30, '2025-01-16 05:59:59.999999'],
            [8, 99, '2025-01-15 06:00:00.000000'],
        ] as [$product, $quantity, $created]) {
            Line::query()->create([
                'product_id' => $product,
                'quantity' => $quantity,
                'price_cents' => $quantity === null ? null : $quantity * 100,
                'created_at' => $created,
                'available_on' => $created === null ? null : mb_substr($created, 0, 10),
            ]);
        }

        Line::query()->create(['product_id' => 5, 'quantity' => 100, 'visible' => false]);
        Line::query()->create(['product_id' => 5, 'quantity' => 100, 'deleted_at' => '2025-01-01']);
    }
}
