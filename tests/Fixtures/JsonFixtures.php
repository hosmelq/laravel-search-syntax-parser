<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Workbench\App\Models\Product;

final class JsonFixtures
{
    public static function ids(string $input, array $fields, array $terms = []): array
    {
        return Product::query()
            ->where('tenant_id', 1)
            ->tap(Search::make()->fields($fields)->terms($terms)->query($input))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function rows(array $values): void
    {
        foreach ($values as $index => $value) {
            $attributes = [
                'id' => $index + 1,
                'name' => 'Product',
                'status' => 'open',
                'meta' => ['value' => $value, 'nested' => ['value' => $value]],
                'tags' => [$value],
            ];

            if (Product::query()->getConnection()->getDriverName() === 'pgsql') {
                $attributes['meta_jsonb'] = $attributes['meta'];
            }

            Product::query()->create($attributes);
        }

        Product::query()->create([
            'id' => count($values) + 1,
            'name' => 'Absent',
            'status' => 'open',
            'meta' => [],
        ]);
        Product::query()->create([
            'id' => count($values) + 2,
            'name' => 'NULL',
            'status' => 'open',
        ]);
    }
}
