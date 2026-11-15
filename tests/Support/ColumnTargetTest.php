<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;
use Illuminate\Database\Query\Expression;

it('groups boolean expressions before applying predicates', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::boolean('in_stock')
            ->expression(fn (): Expression => new Expression('quantity > 0'))
            ->nullable(),
    ])->terms([]);

    expect(ColumnFixtures::ids($input, $configure))->toBe($ids);
})->with([
    ['in_stock:true', [2, 3, 5]],
    ['in_stock:false', [1]],
    ['-in_stock:true', [1, 4]],
    ['in_stock:true,false', [1, 2, 3, 5]],
]);

it('groups expressions with OR before checking nulls and complements', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::boolean('flag')->expression(fn (): Expression => new Expression(
            'quantity > 0 OR rating > 4',
        ))->nullable(),
    ])->terms([]);

    expect(ColumnFixtures::ids($input, $configure))->toBe($ids);
})->with([
    ['flag:*', [1, 2, 3, 5]],
    ['-flag:*', [4]],
    ['flag:*..*', [1, 2, 3, 5]],
    ['-flag:*..*', [4]],
    ['-flag:true', [4]],
]);
