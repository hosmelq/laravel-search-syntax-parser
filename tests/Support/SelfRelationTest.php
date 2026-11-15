<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AggregateFixtures;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Product;

it('keeps Eloquent aliases and correlations along chains of self relations', function (): void {
    AggregateFixtures::seed();

    Product::query()->whereKey(2)->update(['parent_id' => 1]);
    Product::query()->whereKey(3)->update(['parent_id' => 2]);
    Product::query()->whereKey(4)->update(['parent_id' => 3]);

    $field = Field::string('v')->relation('children.children.children', 'name');
    $search = static fn (string $input): Search => Search::make()->fields([$field])->query($input);

    expect(AggregateFixtures::ids('v:*', $field))->toBe([1])
        ->and(AggregateFixtures::ids('v:"Product 4"', $field))->toBe([1])
        ->and(AggregateFixtures::ids('NOT v:*', $field))->toBe([2, 3, 4, 5, 6, 7])
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('v:*'))
                ->update(['status' => 'deep']),
        )->toBe(
            1,
        )
        ->and(Product::query()->where('tenant_id', 1)->tap($search('NOT v:*'))->delete())->toBe(6)
        ->and(Product::query()->orderBy('id')->pluck('id')->all())->toBe([1, 8]);
});

it('updates and deletes rows selected through nested and intermediate reads of the root table', function (): void {
    AggregateFixtures::seed();

    Product::query()->whereKey([2, 3])->update(['parent_id' => 1]);
    Product::query()->whereKey(4)->update(['parent_id' => 2]);

    $search = static fn (string $input, Field $field): Search => Search::make()
        ->fields([$field])
        ->query($input);
    $grandchild = Field::string('grandchild')->relation('children.children', 'name');
    $lineProduct = Field::string('owner')->relation('lines.product', 'name');
    $childLines = Field::integer('items')->countOf('childLines');

    expect(
        Product::query()
            ->where('tenant_id', 1)
            ->tap($search('grandchild:*', $grandchild))
            ->update(['status' => 'grand']),
    )
        ->toBe(1)
        ->and(Product::query()->where('status', 'grand')->pluck('id')->all())->toBe([1])
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('owner:"Product 6"', $lineProduct))
                ->update(['status' => 'six']),
        )
        ->toBe(1)
        ->and(Product::query()->where('status', 'six')->pluck('id')->all())->toBe([6])
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('items:>0', $childLines))
                ->update(['status' => 'lines']),
        )
        ->toBe(1)
        ->and(Product::query()->where('status', 'lines')->pluck('id')->all())->toBe([1])
        ->and(
            Product::query()->where('tenant_id', 1)->tap($search('items:0', $childLines))->delete(),
        )
        ->toBe(6)
        ->and(Product::query()->orderBy('id')->pluck('id')->all())->toBe([1, 8]);
});

it('updates and deletes rows selected through self relations on every driver', function (): void {
    AggregateFixtures::seed();

    Product::query()->whereKey([2, 3])->update(['parent_id' => 1]);
    Product::query()->whereKey(5)->update(['parent_id' => 4]);

    $search = static fn (string $input, Field $field): Search => Search::make()
        ->fields([$field])
        ->query($input);
    $children = Field::integer('items')->countOf('children');
    $parent = Field::string('parent')->relation('parent', 'name');

    expect(
        Product::query()
            ->where('tenant_id', 1)
            ->tap($search('items:>0', $children))
            ->update(['status' => 'parent']),
    )
        ->toBe(2)
        ->and(Product::query()->where('status', 'parent')->orderBy('id')->pluck('id')->all())->toBe(
            [1, 4],
        )
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('parent:"Product 1"', $parent))
                ->update(['status' => 'child']),
        )
        ->toBe(2)
        ->and(Product::query()->where('status', 'child')->orderBy('id')->pluck('id')->all())->toBe([
            2,
            3,
        ])
        ->and(Product::query()->where('tenant_id', 1)->tap($search('-parent:*', $parent))->delete())
        ->toBe(4)
        ->and(Product::query()->orderBy('id')->pluck('id')->all())->toBe([2, 3, 5, 8]);
});

it('updates rows selected through constraints and scopes that read the root table again', function (): void {
    AggregateFixtures::seed();

    Product::query()->whereKey(2)->update(['parent_id' => 1]);

    $search = static fn (string $input, Field $field): Search => Search::make()
        ->fields([$field])
        ->query($input);
    $owned = Field::integer('v')->countOf('lines', static function (Builder $query): void {
        $query->whereHas('product', static function (Builder $product): void {
            $product->where('name', 'Product 1');
        });
    });
    $parents = Field::boolean('v')->scopes([
        true => static function (Builder $query): void {
            $query->whereHas('children');
        },
    ]);

    expect(
        Product::query()
            ->where('tenant_id', 1)
            ->tap($search('v:>0', $owned))
            ->update(['status' => 'owned']),
    )
        ->toBe(1)
        ->and(Product::query()->where('status', 'owned')->pluck('id')->all())->toBe([1])
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('v:true', $parents))
                ->update(['status' => 'parent']),
        )
        ->toBe(1)
        ->and(Product::query()->where('status', 'parent')->pluck('id')->all())->toBe([1]);
});
