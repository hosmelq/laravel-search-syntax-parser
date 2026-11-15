<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Status;
use Illuminate\Database\Query\Expression;
use Workbench\App\Models\Product;

it('binds integer values of non-numeric fields without numeric casts', function (): void {
    ColumnFixtures::seed();

    $membership = static fn (Search $search): Search => $search
        ->fields([Field::in('code', [1, 2, 123])->column('status')])
        ->terms([]);
    $callback = static fn (Search $search): Search => $search
        ->fields([Field::callback('name', static fn (): bool => true)])
        ->terms(['name']);

    expect(ColumnFixtures::ids('code:123', $membership))->toBeEmpty()
        ->and(ColumnFixtures::ids('123', $callback))->toBeEmpty();
});

it('binds mapped and unmapped values of a mapped numeric field without numeric casts', function (): void {
    ColumnFixtures::seed();

    $tier = static fn (Search $search): Search => $search
        ->fields([Field::integer('tier')->map([1 => 'pending'])->column('status')])
        ->terms([]);
    $code = static fn (Search $search): Search => $search
        ->fields([Field::integer('code')->map([1 => '001'])->column('name')])
        ->terms([]);
    $exact = static fn (Search $search): Search => $search
        ->fields([Field::numeric('n')->map([7 => 'seven'])->column('name')])
        ->terms([]);

    Product::query()->whereKey(3)->update(['name' => '1.23456789012345']);
    Product::query()->whereKey(5)->update(['name' => '100000000000000000000']);

    $mixed = static fn (Search $search): Search => $search
        ->fields([Field::integer('tier')->map([1 => 'pending', 2 => 20])->column('status')])
        ->terms([]);
    $flag = static fn (Search $search): Search => $search
        ->fields([Field::boolean('flag')->map([1 => 'open'])->column('status')])
        ->terms([]);

    expect(ColumnFixtures::ids('tier:2', $tier))->toBeEmpty()
        ->and(ColumnFixtures::ids('tier:0', $tier))->toBeEmpty()
        ->and(ColumnFixtures::ids('-tier:0', $tier))->toBe(ColumnFixtures::ids(''))
        ->and(ColumnFixtures::ids('tier:0', $mixed))->toBeEmpty()
        ->and(ColumnFixtures::ids('tier:1', $mixed))->toBe(ColumnFixtures::ids('status:pending'))
        ->and(ColumnFixtures::ids('n:1.23456789012345', $exact))->toBe([3])
        ->and(ColumnFixtures::ids('n:100000000000000000000', $exact))->toBe([5])
        ->and(ColumnFixtures::ids('flag:false', $flag))->toBeEmpty()
        ->and(ColumnFixtures::ids('flag:true', $flag))->toBe(ColumnFixtures::ids('status:open'))
        ->and(ColumnFixtures::ids('tier:1,2', $tier))->toBe(ColumnFixtures::ids('status:pending'))
        ->and(ColumnFixtures::ids('code:1', $code))->toBeEmpty()
        ->and(ColumnFixtures::ids('-code:1', $code))->toBe(ColumnFixtures::ids(''));
});

it('compares fractional numeric candidates against integer storage', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::numeric('q', 'quantity')->nullable(),
    ])->terms(['q']);

    expect(ColumnFixtures::ids($input, $configure))->toBe($ids);
})->with([
    ['q:1.5', []],
    ['-q:1.5', [1, 2, 3, 4, 5]],
    ['q:>1.5', [2, 3, 5]],
    ['q:0,1.5', [1]],
    ['q:0..1.5', [1]],
    ['q:1.5..*', [2, 3, 5]],
    ['1.5', []],
]);

it('compares fractional scaled candidates without integer coercion', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::numeric('cost', 'price_cents')->scale(100)->nullable(),
    ])->terms(['cost']);

    expect(ColumnFixtures::ids($input, $configure))->toBe($ids);
})->with([
    ['cost:10.505', []],
    ['cost:>10.505', [2, 5]],
    ['cost:10.50,10.505', [1]],
    ['cost:10.50..10.505', [1]],
    ['-cost:10.50..10.505', [2, 3, 4, 5]],
    ['10.505', []],
]);

it('compares integer candidates beyond the column range without database errors', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids($input, fn (Search $search): Search => $search->terms(['quantity'])))
        ->toBe($ids);
})->with([
    ['quantity:3000000000', []],
    ['-quantity:3000000000', [1, 2, 3, 4, 5]],
    ['quantity:>3000000000', []],
    ['quantity:0,3000000000', [1]],
    ['quantity:10..3000000000', [2, 3, 5]],
    ['quantity:*..3000000000', [1, 2, 3, 5]],
    ['3000000000', []],
    ['-3000000000', [1, 2, 3, 4, 5]],
]);

it('compares mapped string storage values without numeric casts', function (): void {
    ColumnFixtures::seed();

    $configure = static fn (Search $search): Search => $search
        ->fields([Field::integer('tier')->map([1 => 'pending'])->column('status')])
        ->terms([]);

    expect(ColumnFixtures::ids('tier:1', $configure))->toBe(ColumnFixtures::ids('status:pending'))
        ->and(ColumnFixtures::ids('tier:!=1', $configure))->toBe(
            ColumnFixtures::ids('-status:pending'),
        );
});

it('compares normalized identifiers and members without folding indexed columns', function (
    string $type,
    string $input,
    mixed $value,
    array $ids,
): void {
    ColumnFixtures::seed();

    $field = match ($type) {
        'uuid' => Field::uuid('key', 'uuid'),
        'ulid' => Field::ulid('key', 'ulid'),
        'in' => Field::in('key', ['open', 'pending', 'closed'], 'status'),
        'enum' => Field::enum('key', Status::class, 'status'),
    };

    Product::query()->whereKey(1)->update(['ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    $builder = Product::query()
        ->where('tenant_id', 1)
        ->tap(Search::make()->fields([$field])->query($input));

    expect($builder->toSql())->not->toMatch('/lower\(|COLLATE NOCASE/')
        ->and($builder->getBindings())->toBe([1, $value])
        ->and($builder->orderBy('id')->pluck('id')->all())->toBe($ids);
})->with([
    [
        'uuid',
        'key:550E8400-E29B-41D4-A716-446655440002',
        '550e8400-e29b-41d4-a716-446655440002',
        [2],
    ],
    ['ulid', 'key:01arz3ndektsv4rrffq69g5fav', '01ARZ3NDEKTSV4RRFFQ69G5FAV', [1]],
    ['in', 'key:OPEN', 'open', [1, 4]],
    ['enum', 'key:OPEN', 'open', [1, 4]],
]);

it('compares numeric expression results by value on every driver', function (
    string $type,
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    Product::query()->whereNotIn('id', [1, 2])->update(['price_cents' => null]);
    Product::query()->whereKey(1)->update(['quantity' => 8, 'price_cents' => 10]);
    Product::query()->whereKey(2)->update(['quantity' => 1, 'price_cents' => 10]);

    $field = $type === 'decimal' ? Field::decimal('margin', 0, 2) : Field::numeric('margin');

    $field->expression(fn (): Expression => new Expression(
        '(price_cents - quantity) * 1.0 / price_cents',
    ))->nullable();

    expect(ColumnFixtures::ids($input, fn (Search $search): Search => $search->fields([$field])
        ->terms(['margin'])))->toBe($ids);
})->with(['decimal', 'numeric'])->with([
    ['margin:0.2', [1]],
    ['margin:>0.5', [2]],
    ['margin:0.2,0.9', [1, 2]],
    ['margin:0.2..0.5', [1]],
    ['margin:*..0.5', [1]],
    ['-margin:>0.5', [1, 3, 4, 5]],
    ['0.2', [1]],
]);

it('compares scalar values lists ranges and wildcards with their complements', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids($input))->toBe($ids);
})->with([
    'exact text' => ['name:"ana silva"', [1]],
    'string list ignores case' => ['status:OPEN,PENDING', [1, 2, 4, 5]],
    'string ordering' => ['name:<"carla diaz"', [1, 2, 4]],
    'string between' => ['name:["ana bell" TO "bob stone"]', [1, 2, 4]],
    'string not between' => ['-name:["ana bell" TO "bob stone"]', [3, 5]],
    'text inequality' => ['name:!="ANA SILVA"', [2, 3, 4, 5]],
    'numeric equality' => ['quantity:10', [2]],
    'numeric inequality includes null' => ['quantity:!=10', [1, 3, 4, 5]],
    'greater' => ['quantity:>10', [3, 5]],
    'greater or equal' => ['quantity:>=10', [2, 3, 5]],
    'less' => ['quantity:<10', [1]],
    'less or equal' => ['quantity:<=10', [1, 2]],
    'complement greater' => ['NOT quantity:>10', [1, 2, 4]],
    'complement greater or equal' => ['NOT quantity:>=10', [1, 4]],
    'complement less' => ['NOT quantity:<10', [2, 3, 4, 5]],
    'complement less or equal' => ['NOT quantity:<=10', [3, 4, 5]],
    'numeric list' => ['quantity:0,20', [1, 3]],
    'numeric not in' => ['quantity:!=0,20', [2, 4, 5]],
    'between' => ['quantity:[10 TO 20]', [2, 3]],
    'compact range' => ['quantity:10..20', [2, 3]],
    'not between' => ['-quantity:10..20', [1, 4, 5]],
    'open lower' => ['quantity:*..10', [1, 2]],
    'open upper' => ['quantity:20..*', [3, 5]],
    'not open lower' => ['-quantity:*..10', [3, 4, 5]],
    'not open upper' => ['-quantity:20..*', [1, 2, 4]],
    'scaled exact' => ['price:10.50', [1]],
    'scaled comparison' => ['price:>10.50', [2, 5]],
    'scaled list' => ['price:5,10.50', [1, 3]],
    'scaled range' => ['price:5..20', [1, 2, 3]],
    'scaled complement' => ['-price:5..20', [4, 5]],
    'scaled open range' => ['price:*..10.50', [1, 3]],
    'nullable text complement' => ['description:!="red coffee mug"', [2, 3, 4, 5]],
    'wildcard prefix' => ['name:Ana*', [1, 4]],
    'wildcard infix' => ['name:A*Bell', [4]],
    'wildcard complement' => ['description:!=Red*', [2, 3, 4, 5]],
    'wildcard mixed list' => ['name:Ana*,"Bob Stone"', [1, 2, 4]],
    'wildcard mixed negative list' => ['name:!=Ana*,"Bob Stone"', [3, 5]],
]);

it('escapes SQL wildcards in both field patterns and implicit match modes', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::string('description')->contains()->allowLeadingWildcards()->nullable(),
    ])->terms(['description']);

    foreach (['a%b', 'a_b', 'a!b', 'a\\b', '*a%*', '*a_*', '*a!*', '*a!b*'] as $input) {
        expect(ColumnFixtures::ids('description:'.$input, $configure))->toBe([5]);
    }

    $builder = Product::query()
        ->tap(Search::make()->fields([Field::string('name')->prefix()])->query('name:a%_!'));

    expect($builder->toSql())->toContain("ESCAPE '!'")
        ->and($builder->getBindings())->toBe(['a!%!_!!%']);
});

it('keeps LIKE escapes portable when MySQL backslash escaping is disabled', function (): void {
    ColumnFixtures::seed();

    $connection = Product::query()->getModel()->getConnection();
    $configure = fn (Search $search): Search => $search->fields([
        Field::string('description')->contains()->nullable(),
    ])->terms([]);

    if ($connection->getDriverName() !== 'mysql') {
        expect(ColumnFixtures::ids('description:a\\b', $configure))->toBe([5]);

        return;
    }

    $mode = $connection->scalar('select @@SESSION.sql_mode');

    $connection->statement("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");

    try {
        expect(ColumnFixtures::ids('description:a\\b', $configure))->toBe([5])
            ->and(ColumnFixtures::ids('description:a%b', $configure))->toBe([5])
            ->and(ColumnFixtures::ids('description:a_b', $configure))->toBe([5])
            ->and(ColumnFixtures::ids('description:a!b', $configure))->toBe([5]);
    } finally {
        $connection->statement('SET SESSION sql_mode = ?', [$mode]);
    }
});

it('keeps typed number bindings for maps that store numbers', function (): void {
    ColumnFixtures::seed();

    $integer = static fn (Search $search): Search => $search
        ->fields([Field::integer('q', 'quantity')->map([99 => 10])->nullable()])
        ->terms(['q']);
    $numeric = static fn (Search $search): Search => $search
        ->fields([Field::numeric('q', 'quantity')->map([99 => 10])->nullable()])
        ->terms(['q']);

    expect(ColumnFixtures::ids('q:3000000000', $integer))->toBeEmpty()
        ->and(ColumnFixtures::ids('3000000000', $integer))->toBeEmpty()
        ->and(ColumnFixtures::ids('q:99', $integer))->toBe(ColumnFixtures::ids('quantity:10'))
        ->and(ColumnFixtures::ids('q:1.5', $numeric))->toBeEmpty()
        ->and(ColumnFixtures::ids('q:0..1.5', $numeric))->toBe([1]);
});

it('searches nullable boolean columns and their complements', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    Product::query()->whereKey(1)->update(['active' => true]);
    Product::query()->whereKey(2)->update(['active' => false]);

    expect(ColumnFixtures::ids($input, fn (Search $search): Search => $search->fields([
        Field::boolean('active')->nullable(),
    ])->terms(['active'])))->toBe($ids);
})->with([
    ['active:true', [1]],
    ['active:false', [2]],
    ['-active:true', [2, 3, 4, 5]],
    ['active:true,false', [1, 2]],
    ['active:*', [1, 2]],
    ['false', [2]],
]);

it('supports configured prefix and contains modes while quoted values stay exact', function (): void {
    ColumnFixtures::seed();

    $fields = [
        Field::string('name')->prefix(),
        Field::string('description')->contains()->nullable(),
    ];
    $configure = fn (Search $search): Search => $search->fields($fields)->terms([]);

    expect(ColumnFixtures::ids('name:ana', $configure))->toBe([1, 4])
        ->and(ColumnFixtures::ids('name:"ana"', $configure))->toBeEmpty()
        ->and(ColumnFixtures::ids('name:ana,"Bob Stone"', $configure))->toBe([1, 2, 4])
        ->and(ColumnFixtures::ids('name:!=ana,"Bob Stone"', $configure))->toBe([3, 5])
        ->and(ColumnFixtures::ids('description:coffee', $configure))->toBe([1, 4])
        ->and(ColumnFixtures::ids('description:"coffee"', $configure))->toBeEmpty()
        ->and(ColumnFixtures::ids('-description:coffee', $configure))->toBe([2, 3, 5]);
});

it('supports non-integer scaled decimal storage without lexicographic comparisons', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::decimal('score', 0, 2)->column('rating')->scale('0.5')->nullable(),
    ])->terms(['score']);

    expect(ColumnFixtures::ids('score:>3', $configure))->toBe([1, 2])
        ->and(ColumnFixtures::ids('score:3..9', $configure))->toBe([1, 2, 5])
        ->and(ColumnFixtures::ids('6.50', $configure))->toBe([2]);
});
