<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AggregateFixtures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Line;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;

it('drops selects from related global scopes and rejects limited constraints', function (): void {
    AggregateFixtures::seed();

    Line::addGlobalScope('extra', static function (Builder $query): void {
        $query->selectRaw('? as extra', [999]);
    });

    try {
        expect(AggregateFixtures::ids('v:>1', Field::integer('v')->countOf('lines')))->toBe([1]);
    } finally {
        Line::clearBootedModels();
    }

    $limited = Field::integer('v')->countOf('lines', static function (Builder $query): void {
        $query->limit(1)->offset(1);
    });

    expect(fn (): array => AggregateFixtures::ids('v:*', $limited))->toThrow(
        LogicException::class,
        'cannot limit rows',
    );
});

it('ignores constraint selects, rejects grouping and reads uppercase aliases of the root table', function (): void {
    AggregateFixtures::seed();

    $selected = Field::integer('v')->countOf('lines', static function (Builder $query): void {
        $query->selectRaw('? as extra', [999])->where('quantity', '>', 10);
    });
    $grouped = Field::integer('v')->countOf('lines', static function (Builder $query): void {
        $query->groupBy('quantity');
    });
    $owner = Field::integer('v')->countOf('lines', static function (Builder $query): void {
        $query->join('products AS owner', 'owner.id', '=', 'lines.product_id')
            ->where('owner.name', 'Product 1');
    });

    expect(AggregateFixtures::ids('v:>0', $selected))->toBe([1, 7])
        ->and(fn (): array => AggregateFixtures::ids('v:>0', $grouped))->toThrow(
            LogicException::class,
            'cannot group rows',
        )
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap(Search::make()->fields([$owner])->query('v:>0'))
                ->update(['status' => 'owner']),
        )
        ->toBe(1)
        ->and(Product::query()->where('status', 'owner')->pluck('id')->all())->toBe([1]);
});

it('retains inherited numeric limits and validates aggregate input before SQL', function (): void {
    AggregateFixtures::seed();

    $field = Field::numeric('value')->sumOf('lines', 'quantity')->min(0)->max(30);

    expect(AggregateFixtures::ids('value:30', $field))->toBe([1, 7]);

    foreach (['value:-1', 'value:31', 'value:"x\' OR 1=1 --"'] as $input) {
        expect(fn (): array => AggregateFixtures::ids($input, $field))->toThrow(
            ValidationException::class,
        );
    }

    $field = Field::numeric('value')->sumOf(
        'lines',
        'quantity',
        fn (Builder $query) => $query->where('name', "x' OR 1=1 --"),
    );
    $query = Product::query()->tap(Search::make()->fields([$field])->query('-value:10..20'));

    expect($query->toSql())->not->toContain("x' OR 1=1")
        ->and($query->orderBy('id')->pluck('id')->all())->toBe(range(1, 8));
});

it('applies every operator and its set complement to each aggregate with bindings', function (
    string $function,
    array $values,
    array $constrained,
): void {
    AggregateFixtures::seed();

    foreach ([false, true] as $constraint) {
        foreach ($function === 'count' ? [false] : [false, true] as $replaceEmpty) {
            $callback = $constraint
                ? static fn (Builder $query) => $query->where('quantity', '>=', 10)
                : null;
            $field = $function === 'count'
                ? Field::integer('value')->countOf('lines', $callback)
                : Field::numeric('value')->{$function.'Of'}('lines', 'quantity', $callback);
            $stored = $constraint ? $constrained : $values;

            if ($replaceEmpty) {
                $field->whenEmpty(0);

                $stored = array_map(
                    static fn (null|float|int $value): float|int => $value ?? 0,
                    $stored,
                );
            }

            $pivot = $function === 'count' ? 1 : 10;

            foreach ([
                [(string) $pivot, static fn ($value): bool => $value === $pivot],
                ['>'.$pivot, static fn ($value): bool => $value > $pivot],
                ['>='.$pivot, static fn ($value): bool => $value >= $pivot],
                ['<'.$pivot, static fn ($value): bool => $value < $pivot],
                ['<='.$pivot, static fn ($value): bool => $value <= $pivot],
                ['0,'.$pivot, static fn ($value): bool => in_array($value, [0, $pivot], true)],
                ['0..'.$pivot, static fn ($value): bool => $value >= 0 && $value <= $pivot],
                ['[0 TO '.$pivot.']', static fn ($value): bool => $value >= 0 && $value <= $pivot],
                ['*..'.$pivot, static fn ($value): bool => $value <= $pivot],
                [$pivot.'..*', static fn ($value): bool => $value >= $pivot],
                ['*..*', static fn (): bool => true],
                ['*', static fn (): bool => true],
            ] as [$syntax, $matches]) {
                $expected = array_keys(array_filter(
                    $stored,
                    static fn ($value): bool => $value !== null && $matches($value),
                ));
                $complement = array_values(array_diff(range(1, 7), $expected));

                expect(AggregateFixtures::ids('value:'.$syntax, $field))->toBe($expected)
                    ->and(AggregateFixtures::ids('-value:'.$syntax, $field))->toBe($complement)
                    ->and(AggregateFixtures::ids('NOT value:'.$syntax, $field))->toBe($complement);
            }

            foreach ([(string) $pivot, '0,'.$pivot] as $syntax) {
                expect(AggregateFixtures::ids('value:!='.$syntax, $field))
                    ->toBe(AggregateFixtures::ids('NOT value:'.$syntax, $field))
                    ->and(AggregateFixtures::ids('NOT value:!='.$syntax, $field))
                    ->toBe(AggregateFixtures::ids('value:'.$syntax, $field));
            }
        }
    }
})->with([
    ['count', [1 => 2, 1, 1, 0, 0, 1, 1], [1 => 2, 0, 0, 0, 0, 0, 1]],
    ['sum', [1 => 30, 5, null, null, null, 0, 30], [1 => 30, null, null, null, null, null, 30]],
    ['avg', [1 => 15, 5, null, null, null, 0, 30], [1 => 15, null, null, null, null, null, 30]],
    ['min', [1 => 10, 5, null, null, null, 0, 30], [1 => 10, null, null, null, null, null, 30]],
    ['max', [1 => 20, 5, null, null, null, 0, 30], [1 => 20, null, null, null, null, null, 30]],
]);

it('applies numeric storage maps to aggregate comparisons lists and ranges', function (): void {
    AggregateFixtures::seed();

    $field = Field::numeric('value')->sumOf('lines', 'quantity')->map([1 => 5, 2 => 30]);

    expect(AggregateFixtures::ids('value:1', $field))->toBe([2])
        ->and(AggregateFixtures::ids('value:>1', $field))->toBe([1, 7])
        ->and(AggregateFixtures::ids('value:1,2', $field))->toBe([1, 2, 7])
        ->and(AggregateFixtures::ids('value:1..2', $field))->toBe([1, 2, 7])
        ->and(AggregateFixtures::ids('value:!=1,2', $field))->toBe([3, 4, 5, 6]);

    $field = Field::integer('value')->countOf('lines')->map([10 => 2]);

    expect(AggregateFixtures::ids('value:10', $field))->toBe([1])
        ->and(AggregateFixtures::ids('value:!=10', $field))->toBe(range(2, 7));
});

it('converts sum comparisons lists and range endpoints to their storage scale', function (): void {
    AggregateFixtures::seed();

    $field = Field::decimal('spent', 0, 2)->sumOf('lines', 'price_cents')->scale(100);

    foreach ([
        ['30', [1, 7]],
        ['>=30', [1, 7]],
        ['>5', [1, 7]],
        ['0,5', [2, 6]],
        ['5..30', [1, 2, 7]],
        ['*..5', [2, 6]],
    ] as [$value, $expected]) {
        expect(AggregateFixtures::ids('spent:'.$value, $field))->toBe($expected)
            ->and(AggregateFixtures::ids('NOT spent:'.$value, $field))->toBe(
                array_values(array_diff(range(1, 7), $expected)),
            );
    }

    expect(AggregateFixtures::ids('spent:0', $field->whenEmpty(0)))->toBe([3, 4, 5, 6])
        ->and(AggregateFixtures::ids('spent:!=0', $field))->toBe([1, 2, 7]);
});

it('correlates self relations and groups OR constraints inside their subquery', function (): void {
    AggregateFixtures::seed();

    Product::query()->whereKey(2)->update(['parent_id' => 1, 'quantity' => 5]);
    Product::query()->whereKey(3)->update(['parent_id' => 1, 'quantity' => 10]);
    Product::query()->whereKey(4)->update(['quantity' => 100]);

    $field = Field::numeric('value')->sumOf('children', 'quantity');

    expect(AggregateFixtures::ids('value:15', $field))->toBe([1])
        ->and(AggregateFixtures::ids('value:!=15', $field))->toBe(range(2, 7))
        ->and(AggregateFixtures::ids('value:0', $field->whenEmpty(0)))->toBe(range(2, 7))
        ->and(AggregateFixtures::ids('value:1', Field::integer('value')->countOf('parent')))->toBe([
            2,
            3,
        ]);

    $field = Field::numeric('value')->sumOf(
        'children',
        'quantity',
        static function (Builder $query): void {
            $query->where('quantity', 5)->orWhere('quantity', 100);
        },
    );

    expect(AggregateFixtures::ids('value:5', $field))->toBe([1])
        ->and(AggregateFixtures::ids('value:!=5', $field))->toBe(range(2, 7));
});

it('keeps joins and groups OR conditions that an aggregate constraint adds', function (): void {
    AggregateFixtures::seed();

    $field = Field::integer('v')->countOf('lines', static function (Builder $query): void {
        $query->join('products as owner', 'owner.id', '=', 'lines.product_id')
            ->where('owner.name', 'Product 1')
            ->orWhere('owner.name', 'Product 2');
    });

    expect(AggregateFixtures::ids('v:>0', $field))->toBe([1, 2])
        ->and(AggregateFixtures::ids('v:2', $field))->toBe([1])
        ->and(AggregateFixtures::ids('v:0', $field))->toBe([3, 4, 5, 6, 7]);
});

it('supports belongsTo pivot through and morph relations through native Eloquent correlations', function (): void {
    AggregateFixtures::seed();

    $customer = Customer::query()->create(['name' => 'Customer']);
    $product = Product::query()->findOrFail(1);

    $product->update(['customer_id' => $customer->getKey()]);

    $tag = Tag::query()->create(['name' => 'Tag']);

    $product->tags()->attach($tag);
    $product->polymorphicTags()->attach($tag);
    $product->notes()->create(['name' => 'Note']);
    $product->lines()->firstOrFail()->shipments()->create(['name' => 'Shipment']);

    foreach ([
        'customer',
        'tags',
        'polymorphicTags',
        'notes',
        'shipments',
        'shipment',
    ] as $relation) {
        expect(
            AggregateFixtures::ids('value:1', Field::integer('value')->countOf($relation)),
        )->toBe(
            [1],
        )
            ->and(
                AggregateFixtures::ids('value:0', Field::integer('value')->countOf($relation)),
            )->toBe(
                range(2, 7),
            );
    }
});
