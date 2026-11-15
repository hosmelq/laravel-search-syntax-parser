<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Workbench\App\Models\Line;
use Workbench\App\Models\Product;

final class RelationFixtures
{
    public static function ids(string $input, array $fields, array $terms = []): array
    {
        return Product::query()
            ->where('tenant_id', 1)
            ->tap(self::search($input, $fields, $terms))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function rows(): void
    {
        foreach ([
            [1, 'PHP', null, 10],
            [2, 'Laravel', null, 5],
            [2, 'Ruby', null, 25],
            [3, 'Rails', 'PHP', 20],
            [4, null, null, null],
            [6, 'PHP', null, 10],
            [7, 'PHP', null, 10],
            [8, 'PHP', null, 10],
        ] as [$product, $name, $alternate, $quantity]) {
            Line::query()->create([
                'product_id' => $product,
                'name' => $name,
                'alternate_name' => $alternate,
                'quantity' => $quantity,
                'visible' => $product !== 6,
                'deleted_at' => $product === 7 ? '2025-01-01 00:00:00' : null,
            ]);
        }
    }

    public static function search(string $input, array $fields, array $terms = []): Search
    {
        return Search::make()->fields($fields)->terms($terms)->query($input);
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
    }
}
