<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Priority;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ScopeFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;

it('rejects ordering ranges patterns existence and invalid values as positioned validation errors', function (
    string $syntax,
): void {
    ScopeFixtures::seed();

    foreach ([
        Field::in('is', ['overdue', 'ready'])->scopes(['overdue' => 'overdue', 'ready' => 'ready']),
        Field::presence('is', 'archived_at'),
    ] as $field) {
        try {
            ScopeFixtures::ids('  is:'.$syntax, $field);

            test()->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['q'][0])->toContain('position 5');
        }
    }
})->with([
    '>true',
    '>=true',
    '<true',
    '<=true',
    'true..false',
    '[true TO false]',
    'tr*',
    '*',
    'unknown',
]);

it('groups scope names and callbacks with exact NULL-aware list complements', function (
    bool $scoped,
): void {
    ScopeFixtures::seed();

    $field = Field::in('is', ['overdue', 'ready', 'selected'])->scopes([
        'overdue' => 'overdue',
        'ready' => 'ready',
        'selected' => static function (Builder $query): void {
            $query->where('description', 'Selected')->orWhere('quantity', 30);
        },
    ]);

    foreach ([
        ['overdue', [1, 4]],
        ['ready', [2]],
        ['selected', [1, 3, 4]],
        ['overdue,ready', [1, 2, 4]],
        ['overdue,selected', [1, 3, 4]],
        ['ready,selected', [1, 2, 3, 4]],
    ] as [$value, $expected]) {
        $complement = array_values(array_diff(range(1, 5), $expected));

        expect(ScopeFixtures::ids('is:'.$value, $field, $scoped))->toBe($expected)
            ->and(ScopeFixtures::ids('is:!='.$value, $field, $scoped))->toBe($complement)
            ->and(ScopeFixtures::ids('-is:'.$value, $field, $scoped))->toBe($complement)
            ->and(ScopeFixtures::ids('NOT is:'.$value, $field, $scoped))->toBe($complement)
            ->and(ScopeFixtures::ids('NOT is:!='.$value, $field, $scoped))->toBe($expected);
    }

    expect(ScopeFixtures::ids('is:overdue OR is:ready', $field, $scoped))->toBe([1, 2, 4])
        ->and(ScopeFixtures::ids('is:selected NOT is:overdue', $field, $scoped))->toBe([3])
        ->and(ScopeFixtures::ids('NOT (is:overdue OR is:ready)', $field, $scoped))->toBe([3, 5]);
})->with([false, true]);

it('lets both boolean predicates describe independent matching sets', function (): void {
    ScopeFixtures::seed();

    $field = Field::boolean('flag')->scopes([true => 'overdue', false => 'ready']);

    expect(ScopeFixtures::ids('flag:false', $field))->toBe([2])
        ->and(ScopeFixtures::ids('flag:!=true', $field))->toBe([2, 3, 5])
        ->and(ScopeFixtures::ids('flag:!=false', $field))->toBe([1, 3, 4, 5])
        ->and(ScopeFixtures::ids('flag:true,false', $field))->toBe([1, 2, 4])
        ->and(ScopeFixtures::ids('-flag:true,false', $field))->toBe([3, 5]);
});

it('maps string and integer enum values while retaining aliases and defaults', function (): void {
    ScopeFixtures::seed();

    $field = Field::enum('state', Status::class)->scopes([
        'open' => 'overdue',
        'pending' => 'ready',
        'closed' => static fn (Builder $query) => $query->whereNull('quantity'),
    ])->alias('late', 'open');

    expect(ScopeFixtures::ids('state:OPEN', $field))->toBe([1, 4])
        ->and(ScopeFixtures::ids('state:late', $field))->toBe([1, 4])
        ->and(ScopeFixtures::ids('state:open,Pending', $field))->toBe([1, 2, 4])
        ->and(ScopeFixtures::ids('', $field->default('closed')))->toBe([3, 5]);

    $field = Field::enum('priority', Priority::class)->scopes([10 => 'ready', 20 => 'overdue']);

    expect(ScopeFixtures::ids('priority:high', $field))->toBe([1, 4])
        ->and(ScopeFixtures::ids('priority:10', $field))->toBe([2])
        ->and(ScopeFixtures::ids('priority:!=low,high', $field))->toBe([3, 5]);
});

it('treats a predicate without constraints as the whole matching set inside lists', function (): void {
    ScopeFixtures::seed();

    $field = Field::in('is', ['all', 'overdue'])->scopes([
        'all' => static function (): void {},
        'overdue' => 'overdue',
    ]);

    expect(ScopeFixtures::ids('is:all', $field, true))->toBe(range(1, 5))
        ->and(ScopeFixtures::ids('is:overdue,all', $field, true))->toBe(range(1, 5))
        ->and(ScopeFixtures::ids('is:!=all', $field, true))->toBeEmpty()
        ->and(ScopeFixtures::ids('is:!=overdue,all', $field, true))->toBeEmpty();
});

it('updates and deletes the rows a strict complement selects on every driver', function (): void {
    ScopeFixtures::seed();

    $field = Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue']);
    $flag = Field::presence('archived', 'archived_at');
    $search = static fn (string $input, Field $field): Search
        => Search::make()->fields([$field])->query($input);

    expect(
        Product::query()
            ->where('tenant_id', 1)
            ->tap($search('-is:overdue', $field))
            ->update(['name' => 'Open']),
    )
        ->toBe(3)
        ->and(Product::query()->where('name', 'Open')->orderBy('id')->pluck('id')->all())->toBe([
            2,
            3,
            5,
        ])
        ->and(
            Product::query()
                ->where('tenant_id', 1)
                ->tap($search('-archived:true', $flag))
                ->delete(),
        )->toBe(
            3,
        )
        ->and(Product::query()->orderBy('id')->pluck('id')->all())->toBe([1, 4, 6]);
});

it('uses exact complements for predicate booleans and an inferred true or false scope', function (
    string $mode,
): void {
    ScopeFixtures::seed();

    $predicate = static fn (Builder $query) => $query->where('quantity', '>', 10);
    $field = match ($mode) {
        'column' => Field::presence('flag', 'archived_at'),
        'true name' => Field::boolean('flag')->scopes([true => 'overdue']),
        'true callback' => Field::boolean('flag')->scopes([true => $predicate]),
        'false name' => Field::boolean('flag')->scopes([false => 'overdue']),
        'false callback' => Field::boolean('flag')->scopes(['false' => $predicate]),
        'both' => Field::boolean('flag')->scopes([
            true => 'overdue',
            false => static fn (Builder $query) => Context::complement($query, $predicate),
        ]),
    };
    $positive = str_starts_with($mode, 'false') ? [2, 3, 5] : [1, 4];
    $negative = array_values(array_diff(range(1, 5), $positive));

    foreach ([false, true] as $scoped) {
        expect(ScopeFixtures::ids('flag:true', $field, $scoped))->toBe($positive)
            ->and(ScopeFixtures::ids('flag:false', $field, $scoped))->toBe($negative)
            ->and(ScopeFixtures::ids('flag:!=true', $field, $scoped))->toBe($negative)
            ->and(ScopeFixtures::ids('flag:!=false', $field, $scoped))->toBe($positive)
            ->and(ScopeFixtures::ids('NOT flag:true', $field, $scoped))->toBe($negative)
            ->and(ScopeFixtures::ids('-flag:false', $field, $scoped))->toBe($positive)
            ->and(ScopeFixtures::ids('flag:true,false', $field, $scoped))->toBe(range(1, 5))
            ->and(ScopeFixtures::ids('flag:!=true,false', $field, $scoped))->toBeEmpty();
    }
})->with(['column', 'true name', 'true callback', 'false name', 'false callback', 'both']);
