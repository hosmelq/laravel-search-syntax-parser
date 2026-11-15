<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;

it('accepts mutation-only and replacement configuration callbacks', function (): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids('name:Ana', function (Search $search): void {
        $search->fields([Field::string('name')->prefix()])->terms([]);
    }))->toBe([1, 4])
        ->and(ColumnFixtures::ids('quantity:10', fn (): Search => Search::make()->fields([
            Field::integer('quantity'),
        ])))->toBe([2]);
});
