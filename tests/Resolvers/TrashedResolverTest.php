<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\SortingFixtures;
use Workbench\App\Models\SoftProduct;

it('applies trashed settings to the root even without filters', function (): void {
    SortingFixtures::seed();

    foreach ([
        '' => [1, 3, 4, 5, 6],
        'trashed:without' => [1, 3, 4, 5, 6],
        'trashed:with' => [1, 2, 3, 4, 5, 6],
        'trashed:only' => [2],
        '(quantity:10 OR quantity:30) trashed:with' => [1, 3],
    ] as $input => $ids) {
        expect(
            SoftProduct::query()
                ->whereSearch($input, fn (Search $search): Search => $search->fields([
                    Field::trashed(), Field::integer('quantity')->nullable(),
                ])->terms([]))
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        )->toBe($ids);
    }

    expect(
        SoftProduct::withTrashed()->tap(Search::make()->fields([Field::trashed()]))->count(),
    )->toBe(
        5,
    );
});
