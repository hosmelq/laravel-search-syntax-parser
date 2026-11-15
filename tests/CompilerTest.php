<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\FullTextFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\SortingFixtures;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;

it('keeps eligible term fields when another field rejects a wildcard', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::string('name')->withoutWildcards(),
        Field::string('description')->nullable(),
    ])->terms(['name', 'description']);

    expect(ColumnFixtures::ids('Red*', $configure))->toBe([1])
        ->and(ColumnFixtures::ids('-Red*', $configure))->toBe([2, 3, 4, 5]);
});

it('keeps plain terms away from fields that forbid equality', function (): void {
    ColumnFixtures::seed();

    $configure = static fn (Search $search): Search => $search
        ->fields([
            Field::string('name')->operators('>', '<'),
            Field::integer('quantity')->operators('>'),
        ])
        ->terms(['name', 'quantity']);

    expect(ColumnFixtures::ids('Plain', $configure))->toBeEmpty()
        ->and(ColumnFixtures::ids('10', $configure))->toBeEmpty();
});

it('keeps wildcard terms away from fields that forbid equality', function (): void {
    ColumnFixtures::seed();

    $configure = static fn (Search $search): Search => $search
        ->fields([Field::string('name')->operators('>', '<')])
        ->terms(['name']);

    expect(ColumnFixtures::ids('Pl*', $configure))->toBeEmpty();
});

it('only admits castable typed term fields and their complements', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->terms(['quantity', 'uuid']);

    expect(ColumnFixtures::ids('coffee', $configure))->toBeEmpty()
        ->and(ColumnFixtures::ids('-coffee', $configure))->toBe([1, 2, 3, 4, 5])
        ->and(ColumnFixtures::ids('10', $configure))->toBe([2])
        ->and(ColumnFixtures::ids('-10', $configure))->toBe([1, 3, 4, 5])
        ->and(ColumnFixtures::ids('10*', $configure))->toBeEmpty();
});

it('only includes wildcard term fields whose own rules accept the pattern', function (
    string $rule,
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    $field = match ($rule) {
        'minimum' => Field::string('name')->minWildcardLength(3),
        'disabled' => Field::string('name')->withoutWildcards(),
        'mapped' => Field::string('name')->map(['ana' => 'Ana Silva']),
        'scaled' => Field::numeric('name', 'quantity')->scale(100),
    };

    expect(ColumnFixtures::ids($input, fn (Search $search): Search => $search->fields([$field])
        ->terms(['name'])))->toBe($ids);
})->with([
    ['minimum', 'a*', []],
    ['minimum', '-a*', [1, 2, 3, 4, 5]],
    ['minimum', 'Ana*', [1, 4]],
    ['disabled', 'an*', []],
    ['disabled', '-an*', [1, 2, 3, 4, 5]],
    ['mapped', 'Ana*', []],
    ['scaled', '10*', []],
]);

it('rejects wildcards that do not end a word and skips such terms', function (): void {
    FullTextFixtures::seed();

    foreach (['text:co*ee', 'text:"fresh"*,co*ee'] as $input) {
        expect(fn (): array => FullTextFixtures::ids($input))->toThrow(
            ValidationException::class,
            'may only end a word',
        );
    }

    expect(FullTextFixtures::ids('co*ee', terms: true))->toBeEmpty()
        ->and(FullTextFixtures::ids('coff*', terms: true))->toBe([1, 2, 3, 6]);
})->group('full-text');

it('applies a null default as an absence check', function (): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids(null, fn (Search $search): Search => $search->fields([
        Field::integer('quantity')->default(null),
    ])->terms([])))->toBe([4]);
});

it('applies field defaults, aliases, ignored clauses and maps through the core', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::string('status')->default('open')->ignore('any'),
        Field::integer('quantity')->alias('@ten', 10)->nullable(),
    ])->terms([]);

    expect(ColumnFixtures::ids('', $configure))->toBe([1, 4])
        ->and(ColumnFixtures::ids('status:any', $configure))->toBe([1, 2, 3, 4, 5])
        ->and(ColumnFixtures::ids('status:pending', $configure))->toBe([2, 5])
        ->and(ColumnFixtures::ids('status:any quantity:@ten', $configure))->toBe([2]);

    $mapped = fn (Search $search): Search => $search->fields([
        Field::in('amount', ['low', 'high'])
            ->column('quantity')
            ->map(['low' => 0, 'high' => 20])
            ->nullable(),
    ])->terms([]);

    expect(ColumnFixtures::ids('amount:HIGH', $mapped))->toBe([3])
        ->and(ColumnFixtures::ids('amount:low,high', $mapped))->toBe([1, 3]);
});

it('casts and converts decimal term values before querying storage', function (): void {
    ColumnFixtures::seed();

    expect(
        ColumnFixtures::ids('10.50', fn (Search $search): Search => $search->terms(['price'])),
    )->toBe(
        [1],
    );
});

it('combines boolean chains while preserving surrounding constraints', function (): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids('status:open OR status:pending OR status:closed'))->toBe([
        1,
        2,
        3,
        4,
        5,
    ])
        ->and(ColumnFixtures::ids('quantity:>=0 quantity:<=20 quantity:!=10'))->toBe([1, 3])
        ->and(
            ColumnFixtures::ids('NOT (status:open OR status:pending OR status:closed)'),
        )->toBeEmpty();
});

it('groups boolean clauses and eligible terms with their complements', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids($input))->toBe($ids);
})->with([
    'double negation' => ['NOT quantity:!=10', [2]],
    'boolean AND before OR' => ['status:open quantity:0 OR name:"Bob Stone"', [1, 2]],
    'mixed boolean grouping' => ['(status:open OR status:pending) quantity:>=10', [2, 5]],
    'de Morgan' => ['NOT (status:open OR quantity:>10)', [2]],
    'de Morgan OR' => ['NOT (status:open quantity:>10)', [1, 2, 3, 4, 5]],
    'free term' => ['coffee', [1, 4]],
    'phrase' => ['"RED COFFEE"', [1]],
    'two terms' => ['red coffee', [1]],
    'negated term' => ['-coffee', [2, 3, 5]],
    'term integer guard' => ['10', [2, 3]],
    'term numeric guard' => ['4.5', [1]],
    'term uuid guard' => ['550E8400-E29B-41D4-A716-446655440002', [2]],
    'term wildcard text only' => ['Red*', [1]],
    'negative wildcard term' => ['-Red*', [2, 3, 4, 5]],
    'quoted asterisk is literal' => ['"co*fee"', []],
    'literal percent in free term' => ['a%b', [5]],
    'literal underscore in free term' => ['a_b', [5]],
    'literal escape in free term' => ['a!b', [5]],
    'literal backslash in free term' => ['"a\\b"', [5]],
]);

it('isolates custom resolver OR conditions within each leaf', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::string('custom')->using(
            function (Builder $query, Clause $clause, Context $context): void {
                expect($context->field->getName())->toBe('custom')
                    ->and($clause->field)->toBe('custom')
                    ->and($clause->position)->not->toBeNull();

                $query->where('name', $clause->value)->orWhere('status', $clause->value);
            },
        ),
        Field::integer('quantity'),
    ])->terms([]);

    expect(ColumnFixtures::ids('custom:open quantity:0', $configure))->toBe([1])
        ->and(ColumnFixtures::ids('quantity:0 custom:open', $configure))->toBe([1])
        ->and(ColumnFixtures::ids('quantity:0 OR custom:open', $configure))->toBe([1, 4]);
});

it('passes stored values and complemented operators to resolver objects', function (): void {
    ColumnFixtures::seed();

    $resolver = new class () implements Resolver {
        #[Override]
        public function apply(Builder $query, Clause $clause, Context $context): void
        {
            expect($clause->value)->toBe(1_050)
                ->and($clause->operator)->toBe(Operator::NotEqual)
                ->and($clause->negated)->toBeTrue();

            $query->where('price_cents', '!=', $clause->value)->orWhereNull('price_cents');
        }
    };

    expect(ColumnFixtures::ids('cost:!=10.50', fn (Search $search): Search => $search->fields([
        Field::decimal('cost', 0, 2)->scale(100)->using($resolver),
    ])->terms([])))->toBe([2, 3, 4, 5]);
});

it('preserves tenant constraints and global scopes across OR expressions', function (): void {
    ColumnFixtures::seed();

    $builder = Product::query()->withGlobalScope(
        'tenant',
        fn (Builder $query) => $query->where('tenant_id', 1),
    );

    expect(
        $builder->whereSearch('status:open OR quantity:10')->orderBy('id')->pluck('id')->all(),
    )->toBe(
        [1, 2, 4],
    );
});

it('runs converted custom directives and defaults on the root with value positions', function (): void {
    SortingFixtures::seed();

    $field = Field::integer('take')->directive()->min(1)->max(5)->default(2)->scale(10)
        ->using(function (Builder $query, Clause $clause, Context $context): void {
            expect($clause->operator)->toBe(Operator::Equal)->and($clause->value)->toBeInt()
                ->and($context->field->getName())->toBe('take')
                ->and($clause->position)->toBe($clause->value === 20 ? null : 5);

            $query->limit($clause->value);
        });

    foreach (['' => 20, 'take:3' => 30] as $input => $count) {
        $query = Product::query()->tap(Search::make()->fields([$field])->query($input));

        expect($query->getQuery()->limit)->toBe($count)
            ->and($query->getQuery()->wheres)->toBeEmpty()
            ->and($query->get())->toHaveCount(Product::query()->count());
    }
});

it('uses all columns of a term field for positive and negative terms', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->terms(['person']);

    expect(ColumnFixtures::ids('ana', $configure))->toBe([1, 4])
        ->and(ColumnFixtures::ids('-ana', $configure))->toBe([2, 3, 5])
        ->and(ColumnFixtures::ids('Ana*', $configure))->toBe([1, 4]);
});

it('uses converted numeric storage for a mapped string term', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::string('level')->column('quantity')->map(['low' => 0, 'high' => 20])->nullable(),
    ])->terms(['level']);

    expect(ColumnFixtures::ids('high', $configure))->toBe([3]);
});

it('uses full-text for terms and ORs separately indexed targets with strict complements', function (): void {
    FullTextFixtures::seed();

    $sqlite = Product::query()->getModel()->getConnection()->getDriverName() === 'sqlite';

    expect(FullTextFixtures::ids('coffee', terms: true))->toBe($sqlite ? [1, 2, 3, 6] : [1, 2, 6])
        ->and(FullTextFixtures::ids('"coffee ceramic"', terms: true))->toBe(
            $sqlite ? [1, 2, 3, 6] : [1, 6],
        )
        ->and(FullTextFixtures::ids('coff*', terms: true))->toBe([1, 2, 3, 6])
        ->and(FullTextFixtures::ids('-coff*', terms: true))->toBe([4, 5])
        ->and(FullTextFixtures::ids('coffee', terms: true, multiple: true))->toBe(
            $sqlite ? [1, 2, 3, 4, 6] : [1, 2, 4, 6],
        )
        ->and(FullTextFixtures::ids('text:coffee', multiple: true))->toBe(
            $sqlite ? [1, 2, 3, 4, 6] : [1, 2, 4, 6],
        )
        ->and(FullTextFixtures::ids('NOT text:coff*,espresso', multiple: true))->toBeEmpty();
})->group('full-text');
