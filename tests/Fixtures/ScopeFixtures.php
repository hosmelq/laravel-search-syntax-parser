<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Workbench\App\Models\Product;
use Workbench\App\Models\ScopedProduct;

final class ScopeFixtures
{
    public static function ids(string $input, Field $field, bool $scoped = false): array
    {
        $query = $scoped ? ScopedProduct::query() : Product::query()->where('tenant_id', 1);

        return $query->tap(Search::make()->fields([$field])->query($input))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function seed(): void
    {
        foreach ([20, 5, null, 30, null, 20] as $index => $quantity) {
            Product::query()->create([
                'id' => $index + 1,
                'name' => 'Product '.($index + 1),
                'status' => 'open',
                'tenant_id' => $index === 5 ? 2 : 1,
                'quantity' => $quantity,
                'description' => in_array($index, [0, 2, 5], true) ? 'Selected' : null,
                'archived_at' => in_array($index, [0, 3, 5], true) ? '2025-01-15' : null,
            ]);
        }
    }
}
