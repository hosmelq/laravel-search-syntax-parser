<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\SortingFixtures;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use Workbench\App\Models\Product;

it('sorts configured columns and JSON paths with NULL last in both directions', function (
    string $target,
): void {
    SortingFixtures::seed();

    $configure = fn (Search $search): Search => $search->sorts([
        AllowedSort::field('rank', $target)->defaultDirection('desc'),
    ])->defaultSort('rank-asc');

    foreach ([
        '' => [3, 4, 1],
        'sort:rank-asc' => [3, 4, 1],
        'sort:RANK' => [1, 4, 3],
        'sort:rank-desc' => [1, 4, 3],
    ] as $input => $prefix) {
        $ids = Product::query()->whereSearch($input, $configure)->pluck('id')->all();

        expect(array_slice($ids, 0, 3))->toBe($prefix)
            ->and(array_slice($ids, 3))->toEqualCanonicalizing([2, 5, 6]);
    }
})->with(['quantity', 'meta->value']);

it('sorts JSON numbers as numbers with NULL last on every driver', function (): void {
    SortingFixtures::seed();

    Product::query()->whereKey(1)->update(['meta' => ['rank' => 9]]);
    Product::query()->whereKey(3)->update(['meta' => ['rank' => 10]]);
    Product::query()->whereKey(4)->update(['meta' => ['rank' => 2.5]]);
    Product::query()->whereKey(5)->update(['meta' => ['rank' => -1]]);

    $configure = fn (Search $search): Search
        => $search->sorts([AllowedSort::field('rank', 'meta->rank')]);

    expect(Product::query()->whereSearch('sort:rank-asc', $configure)->pluck('id')->take(4)->all())
        ->toBe([5, 4, 1, 3])
        ->and(
            Product::query()
                ->whereSearch('sort:rank-desc', $configure)
                ->pluck('id')
                ->take(4)
                ->all(),
        )
        ->toBe([3, 1, 4, 5]);
});

it('sorts JSON numbers beyond double and decimal precision exactly', function (
    float|int $low,
    float|int $high,
): void {
    SortingFixtures::seed();

    Product::query()->whereKey(1)->update(['meta' => ['rank' => $high]]);
    Product::query()->whereKey(3)->update(['meta' => ['rank' => $low]]);

    $configure = fn (Search $search): Search
        => $search->sorts([AllowedSort::field('rank', 'meta->rank')]);

    expect(Product::query()->whereSearch('sort:rank-asc', $configure)->pluck('id')->take(2)->all())
        ->toBe([3, 1])
        ->and(
            Product::query()
                ->whereSearch('sort:rank-desc', $configure)
                ->pluck('id')
                ->take(2)
                ->all(),
        )
        ->toBe([1, 3]);
})->with([
    'decimals below the decimal scale' => [-2e-31, -1e-31],
    'integers beyond double precision' => [-9_007_199_254_740_993, -9_007_199_254_740_992],
]);

it('sorts JSON strings lexically with NULL last in both directions', function (
    string $direction,
    array $expected,
): void {
    SortingFixtures::seed();

    foreach ([1 => 'zebra', 3 => '10', 4 => '2', 5 => 'apple'] as $id => $value) {
        Product::query()->whereKey($id)->update(['meta' => ['rank' => $value]]);
    }

    $ids = Product::query()
        ->whereSearch('sort:rank-'.$direction, fn (Search $search): Search => $search->sorts([
            AllowedSort::field('rank', 'meta->rank'),
        ]))
        ->pluck('id')
        ->all();

    expect(array_slice($ids, 0, 4))->toBe($expected)
        ->and(array_slice($ids, 4))->toEqualCanonicalizing([2, 6]);
})->with([
    'ascending' => ['asc', [3, 4, 5, 1]],
    'descending' => ['desc', [1, 5, 4, 3]],
]);
