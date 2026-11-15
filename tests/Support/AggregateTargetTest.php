<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AggregateFixtures;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Line;

it('binds nonzero empty values in storage units without scaling them again', function (): void {
    AggregateFixtures::seed();

    $field = Field::decimal('spent', 0, 2)
        ->sumOf('lines', 'price_cents', fn (Builder $query) => $query->where('quantity', '>', 10))
        ->scale(100)
        ->whenEmpty(500);

    expect(AggregateFixtures::ids('spent:5', $field))->toBe([2, 3, 4, 5, 6])
        ->and(AggregateFixtures::ids('spent:!=5', $field))->toBe([1, 7]);
});

it('preserves fractional averages and numeric empty values across database types', function (): void {
    AggregateFixtures::seed();

    Line::query()->create(['product_id' => 2, 'quantity' => 6]);

    $field = Field::numeric('value')->avgOf('lines', 'quantity');

    expect(AggregateFixtures::ids('value:5.5', $field))->toBe([2])
        ->and(AggregateFixtures::ids('value:5.4..5.6', $field))->toBe([2])
        ->and(AggregateFixtures::ids('value:!=5.5', $field))->toBe([1, 3, 4, 5, 6, 7]);

    foreach (['sum', 'avg', 'min', 'max'] as $function) {
        foreach ([1.25, '1.25'] as $empty) {
            $field = Field::numeric('value')
                ->{$function.'Of'}('lines', 'quantity')
                ->whenEmpty($empty);

            expect(AggregateFixtures::ids('value:1.25', $field))->toBe([3, 4, 5])
                ->and(AggregateFixtures::ids('value:1.2..1.3', $field))->toBe([3, 4, 5])
                ->and(AggregateFixtures::ids('value:!=1.25', $field))->toBe([1, 2, 6, 7]);
        }
    }
});
