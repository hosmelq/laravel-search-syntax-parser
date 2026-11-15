<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ScopeFixtures;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Line;
use Workbench\App\Models\Product;
use Workbench\App\Models\ScopedProduct;

it('rejects models without one named primary key', function (mixed $key): void {
    ScopeFixtures::seed();

    $model = new Product()->setKeyName($key);

    expect(
        fn ()
            => Context::complement(
                $model->newQuery(),
                static fn (Builder $query) => $query->where('quantity', 10),
            ),
    )->toThrow(LogicException::class, 'single primary key');
})->with([null, '', [['tenant_id', 'id']]]);

it('exposes the strict complement helper to custom resolvers without importing base constraints', function (): void {
    ScopeFixtures::seed();

    $field = Field::boolean('flag')
        ->operators('=', '!=')->withoutLists()->withoutRanges()->withoutWildcards()->withoutExists()
        ->using(static function (Builder $query, Clause $clause, Context $context): void {
            $positive = static function (Builder $matches): void {
                $matches->where('quantity', '>', 10)->orWhere('description', 'Selected');
            };

            if ((bool) $clause->value !== $clause->negated) {
                $query->where($positive);
            } else {
                $context->complement($query, $positive);
            }
        });

    expect(ScopeFixtures::ids('flag:true', $field, true))->toBe([1, 3, 4])
        ->and(ScopeFixtures::ids('flag:false', $field, true))->toBe([2, 5])
        ->and(ScopeFixtures::ids('flag:!=true', $field, true))->toBe([2, 5]);

    $query = Product::query()->where('quantity', 5);

    Context::complement(
        $query,
        static fn (Builder $matches) => $matches->where('quantity', '>', 10),
    );

    expect($query->pluck('id')->all())->toBe([2]);
});

it('keeps the complemented subquery free of root global scopes', function (): void {
    ScopeFixtures::seed();

    $field = Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue']);
    $query = ScopedProduct::query()->tap(Search::make()->fields([$field])->query('-is:overdue'));

    expect($query->orderBy('id')->pluck('id')->all())->toBe([2, 3, 5])
        ->and(mb_substr_count($query->toSql(), 'tenant_id'))->toBe(1)
        ->and($query->toSql())->toContain('not in (select');
});

it('lets scope predicates select columns and use soft delete macros', function (): void {
    ScopeFixtures::seed();

    $selecting = Field::boolean('v')->scopes([
        true => static function (Builder $query): void {
            $query->select('quantity')->where('quantity', '>', 10);
        },
    ]);

    foreach ([[1, null], [1, '2025-01-01 00:00:00']] as $index => [$product, $deleted]) {
        Line::query()->create([
            'id' => $index + 1,
            'product_id' => $product,
            'deleted_at' => $deleted,
        ]);
    }

    $trashed = Field::boolean('trashed')->scopes([
        true => static function (Builder $query): void {
            $query->onlyTrashed();
        },
    ]);

    expect(ScopeFixtures::ids('v:true', $selecting))->toBe([1, 4])
        ->and(ScopeFixtures::ids('v:false', $selecting))->toBe([2, 3, 5])
        ->and(Line::withTrashed()
            ->tap(Search::make()->fields([$trashed])->query('trashed:true'))
            ->pluck('id')
            ->all())->toBe([2])
        ->and(Line::withTrashed()
            ->tap(Search::make()->fields([$trashed])->query('trashed:false'))
            ->pluck('id')
            ->all())->toBe([1]);
});

it('runs scope predicates in key subqueries that keep joins and reads of the same table', function (): void {
    ScopeFixtures::seed();

    Product::query()->whereKey(2)->update(['parent_id' => 1]);
    Product::query()->whereKey(3)->update(['parent_id' => 1]);

    $search = static fn (string $input, Field $field): Search
        => Search::make()->fields([$field])->query($input);
    $joined = Field::boolean('v')->scopes([
        true => static function (Builder $query): void {
            $query->join('products as parent', 'parent.id', '=', 'products.parent_id')
                ->where('parent.quantity', '>', 10);
        },
    ]);
    $parents = Field::boolean('v')->scopes([
        true => static function (Builder $query): void {
            $query->has('children', '>=', 2);
        },
    ]);
    $listed = Field::boolean('v')->scopes([
        true => static function (Builder $query): void {
            $query->whereIn('id', Product::query()->select('id')->where('quantity', '>', 10));
        },
    ]);

    expect(ScopeFixtures::ids('v:true', $joined))->toBe([2, 3])
        ->and(ScopeFixtures::ids('v:false', $joined))->toBe([1, 4, 5])
        ->and(ScopeFixtures::ids('v:true', $parents))->toBe([1])
        ->and(ScopeFixtures::ids('v:true', $listed))->toBe([1, 4])
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('v:true', $parents))
                ->update(['name' => 'Parent']),
        )
        ->toBe(1)
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('v:true', $listed))
                ->update(['name' => 'Large']),
        )
        ->toBe(2)
        ->and(Product::query()->where('tenant_id', 1)->tap($search('v:false', $listed))->delete())
        ->toBe(3);
});
