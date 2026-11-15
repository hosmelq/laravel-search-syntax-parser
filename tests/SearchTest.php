<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use HosmelQ\SearchSyntaxParser\Date\DateValue;
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AuthorizationFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\DateFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\SortingFixtures;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Validation\Field as CoreField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;
use Workbench\App\Models\SoftProduct;

it('enforces package defaults before SQL is applied', function (
    string $input,
    string $message,
): void {
    try {
        Product::query()->whereSearch($input);

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['q'][0])->toContain($message);
    }
})->with([
    [str_repeat('a', 1_001), '1000 characters'],
    [str_repeat('(', 6).'name:ana'.str_repeat(')', 6), 'depth of 5'],
    [implode(' ', array_fill(0, 51, 'name:ana')), '50 clauses'],
]);

it('keeps fielded wildcard restrictions consistent with term eligibility', function (
    string $input,
    Field $field,
): void {
    expect(fn () => Product::query()->tap(Search::make()->fields([$field])->query($input)))
        ->toThrow(ValidationException::class);
})->with([
    ['name:a*', Field::string('name')->minWildcardLength(3)],
    ['name:an*', Field::string('name')->withoutWildcards()],
    ['name:an*', Field::string('name')->map(['ana' => 'Ana Silva'])],
    ['q:10*', Field::numeric('q', 'quantity')->scale(100)],
]);

it('never permits arbitrary fields when the allowlist is empty', function (): void {
    expect(fn () => Product::query()->tap(Search::make()->query('name:ana')))
        ->toThrow(ValidationException::class, 'Field "name" is not allowed.');
});

it('rejects date and dynamic fields as term targets', function (Field $field): void {
    expect(
        fn ()
            => Product::query()->tap(Search::make()->fields([$field])->terms([$field->getName()])),
    )->toThrow(InvalidArgumentException::class, 'must be an exact configured field');
})->with([
    Field::date('created'),
    Field::date('created')->relation('lines'),
    Field::string('meta.*')->keys('/.*/')->column('meta->*'),
    Field::string('meta.*')->keys('/.*/')->relation('lines', 'meta->*'),
]);

it('rejects directives in terms() even when they are unauthorized', function (): void {
    AuthorizationFixtures::seed();

    Product::query()->tap(Search::make()->fields([
        Field::string('limit')
            ->directive()
            ->using(static function (): void {})
            ->authorize(static fn (): bool => false),
    ])->terms(['limit']))->get();
})->throws(InvalidArgumentException::class, 'must be an exact configured field');

it('rejects duplicate public names when running the search', function (): void {
    $search = Search::make()->fields([Field::string('name'), Field::integer('name', 'quantity')]);

    Product::query()->tap($search);
})->throws(InvalidArgumentException::class, "Field 'name' is configured more than once.");

it('rejects invalid defaults even on denied fields', function (): void {
    Product::query()->tap(Search::make()->fields([
        Field::integer('secret', 'quantity')
            ->default('invalid')
            ->authorize(static fn (): bool => false),
    ]));
})->throws(InvalidArgumentException::class, "Invalid default for field 'secret'.");

it('rejects invalid directive and sort placements as validation errors', function (
    string $input,
): void {
    SortingFixtures::seed();

    expect(
        fn ()
            => SoftProduct::query()->tap(
                Search::make()
                    ->fields([Field::trashed(), Field::integer('quantity')])
                    ->sorts(['quantity'])
                    ->query($input),
            ),
    )->toThrow(ValidationException::class);
})->with([
    'NOT trashed:only', '-trashed:only', 'trashed:only OR quantity:10', 'trashed:only trashed:with',
    'trashed:with,only', 'trashed:*', 'trashed:!=only', 'trashed:with*', 'trashed:with..only',
    'sort:quantity OR quantity:10', 'NOT sort:quantity', 'sort:quantity sort:quantity-desc', 'sort:quantity,quantity',
    'sort:missing', 'trashed:unknown',
]);

it('rejects invalid field definitions and duplicate public names', function (array $fields): void {
    expect(fn (): array => Search::make()->fields($fields)->jsonSerialize())
        ->toThrow(InvalidArgumentException::class);
})->with([
    'core field' => [[CoreField::string('name')]],
    'object' => [[new stdClass()]],
    'string' => [['name']],
    'duplicate name' => [[Field::string('name'), Field::integer('name', 'quantity')]],
]);

it('rejects invalid zones at the appropriate configuration or invocation boundary', function (): void {
    DateFixtures::seed();

    expect(fn (): Search => Search::make()->timezone('Invalid/Zone'))->toThrow(
        DateInvalidTimeZoneException::class,
    );

    $search = Search::make()->timezone(fn (): string => 'Invalid/Zone');

    expect(fn () => Product::query()->tap($search))->toThrow(DateInvalidTimeZoneException::class);
});

it('rejects unconfigured user sorts and default sorts', function (): void {
    expect(
        fn () => Product::query()->whereSearch(
            'sort:"quantity; drop table products"',
            fn (Search $search): Search => $search->sorts(['quantity']),
        ),
    )->toThrow(ValidationException::class)
        ->and(
            fn () => Product::query()->whereSearch(
                '',
                fn (Search $search): Search => $search->sorts(['quantity'])->defaultSort('missing'),
            ),
        )->toThrow(InvalidArgumentException::class);
});

it('rejects unknown term fields and invalid query limits', function (): void {
    expect(fn () => Product::query()->tap(Search::make()->terms(['missing'])))
        ->toThrow(InvalidArgumentException::class, 'must be an exact configured field');

    foreach (['maxClauses', 'maxDepth', 'maxLength'] as $limit) {
        expect(fn () => Product::query()->tap(Search::make()->{$limit}(0)))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('removes unauthorized fields from errors, defaults and term targets', function (): void {
    AuthorizationFixtures::seed();

    $search = Search::make()->fields([
        Field::string('name'),
        Field::integer('secret', 'quantity')->default(10)->authorize(static fn (): bool => false),
        Field::string('directive')
            ->directive()
            ->default('hidden')
            ->using(static function (): void {
                throw new LogicException('An unauthorized directive must not run.');
            })
            ->authorize(static fn (): bool => false),
    ])->terms(['secret', 'name']);

    expect(Product::query()->tap($search)->pluck('id')->all())->toBe([1, 2])
        ->and(Product::query()->tap($search->query('10'))->pluck('id')->all())->toBeEmpty()
        ->and(Product::query()->tap($search->query('coffee'))->pluck('id')->all())->toBe([1])
        ->and(Product::query()->tap($search->query('-10'))->pluck('id')->all())->toBe([1, 2]);

    foreach (['secret:10', 'directive:hidden', 'unknown:value'] as $input) {
        try {
            Product::query()->tap($search->query($input));

            test()->fail('Expected an unknown field error.');
        } catch (ValidationException $validationException) {
            $message = $validationException->errors()['q'][0];

            expect($message)->toContain('Allowed fields: name.')
                ->not->toContain('Allowed fields: name, secret');
        }
    }
});

it('says no fields are allowed when every field is denied', function (): void {
    AuthorizationFixtures::seed();

    $search = Search::make()->fields([
        Field::string('name')->authorize(static fn (): bool => false),
    ])->query('name:coffee');

    expect(fn () => Product::query()->tap($search)->get())
        ->toThrow(
            ValidationException::class,
            'Field "name" is not allowed. No fields are allowed.',
        );
});

it('turns non-string query input into validation errors on both public entry points', function (
    mixed $input,
): void {
    expect(fn () => Product::query()->whereSearch($input))->toThrow(
        ValidationException::class,
        'The search query must be a string.',
    );

    try {
        Product::query()->tap(Search::make()->query($input)->errorKey('filters.q'));

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toBe([
            'filters.q' => ['The search query must be a string.'],
        ]);
    }
})->with([
    'query-string array' => [['x']],
    'empty array' => [[]],
    'boolean' => [false],
    'integer' => [1],
    'float' => [1.5],
    'object' => [new stdClass()],
]);

it('turns parser errors into positioned validation messages', function (
    string $input,
    string $message,
): void {
    try {
        Product::query()->whereSearch($input);

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('q')
            ->and($validationException->errors()['q'][0])->toContain($message, 'At position');
    }
})->with([
    ['private:secret', 'Field "private" is not allowed.'],
    ['quantity:nope', 'Invalid value for field "quantity".'],
    ['quantity:', 'Expected a search value.'],
    ['name:"broken', 'The quoted search value is not closed.'],
    ['name:*ana', 'Leading wildcards are not allowed.'],
    ['name:>ana*', 'Wildcards require equality'],
    ['quantity:!=*', 'Use quantity:* for existence'],
]);

it('uses configured defaults for limits and the error key', function (): void {
    config()->set('search-syntax-parser.max_length', 3);
    config()->set('search-syntax-parser.error_key', 'filter');

    try {
        Product::query()->whereSearch('abcd');

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toBe([
            'filter' => ['The search query exceeds 3 characters. At position 3.'],
        ]);
    }
});

it('uses translated parameters and the configured error key', function (): void {
    Lang::addLines([
        'errors.query.maximum_clauses' => 'Only :limit clauses are allowed.',
        'errors.position' => 'Offset :position.',
    ], 'en', 'search-syntax-parser');

    try {
        Product::query()->whereSearch('name:one name:two', fn (Search $search): Search => $search
            ->maxClauses(1)->errorKey('filter'));

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toBe([
            'filter' => ['Only 1 clauses are allowed. Offset 9.'],
        ]);
    }
});

it('accepts Stringable query input', function (): void {
    ColumnFixtures::seed();

    expect(
        Product::query()
            ->where('tenant_id', 1)
            ->whereSearch(str('status:pending'))
            ->orderBy('id')
            ->pluck('id')
            ->all(),
    )->toBe(ColumnFixtures::ids('status:pending'));
});

it('appends sorting after existing builder orders', function (): void {
    SortingFixtures::seed();

    $query = Product::query()
        ->orderByDesc('id')
        ->whereSearch(
            'sort:quantity-asc',
            fn (Search $search): Search => $search->sorts(['quantity']),
        );

    expect($query->pluck('id')->all())->toBe([6, 5, 4, 3, 2, 1]);
});

it('binds input values and never treats them as SQL', function (): void {
    $input = 'name:"x\' OR 1=1 --"';
    $builder = Product::query()->whereSearch($input);

    expect($builder->toSql())->not->toContain('OR 1=1')
        ->and($builder->getBindings())->toBe(["x' OR 1=1 --"])
        ->and($builder->count())->toBe(0);
});

it('defaults to the app zone and lets a null closure result fall back to it', function (): void {
    DateFixtures::seed();

    config()->set('app.timezone', 'America/Managua');

    Product::query()->whereKey(1)->update(['created_at' => '2025-01-14 23:59:59']);
    Product::query()->whereKey(3)->update(['created_at' => '2025-01-15 23:59:59']);

    $fields = [Field::date('created')->column('created_at')];
    $query = Product::query()
        ->where('tenant_id', 1)
        ->tap(Search::make()->fields($fields)->query('created:2025-01-15'));

    expect($query->orderBy('id')->pluck('id')->all())->toBe([2, 3])
        ->and(DateFixtures::ids('created:2025-01-15', fn (): null => null))->toBe([2, 3])
        ->and(DateFixtures::ids('created:2025-01-15', fn (): string => 'UTC'))->toBe([1, 2])
        ->and(DateFixtures::ids('created:2025-01-15', new DateTimeZone('UTC')))->toBe([1, 2]);
});

it('describes authorized fields and sorts without their database targets', function (): void {
    $search = Search::make()
        ->fields([
            Field::in('status', ['open', 'closed'], 'state')->operators('=', '!=')->withoutRanges(),
            Field::integer('cost')->authorize(static fn (): bool => false),
            Field::string('name')->column('full_name')->authorize(static fn (): bool => true),
            Field::trashed()->authorize(static fn (): bool => false),
        ])
        ->sorts(['name', AllowedSort::field('created', 'created_at')->defaultDirection('desc')]);
    $json = json_encode($search, JSON_THROW_ON_ERROR);

    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'fields' => [
            [
                'aliases' => [],
                'clauses' => ['exists', 'lists'],
                'date' => null,
                'directive' => false,
                'items' => null,
                'match' => null,
                'name' => 'status',
                'names' => null,
                'operators' => ['=', '!='],
                'pattern' => false,
                'type' => 'in',
                'values' => ['open', 'closed'],
            ],
            [
                'aliases' => [],
                'clauses' => ['exists', 'lists', 'ranges', 'wildcards'],
                'date' => null,
                'directive' => false,
                'items' => null,
                'match' => 'exact',
                'name' => 'name',
                'names' => null,
                'operators' => ['=', '!=', '>', '>=', '<', '<='],
                'pattern' => false,
                'type' => 'string',
                'values' => null,
            ],
        ],
        'sorts' => [
            ['direction' => 'asc', 'name' => 'name'],
            ['direction' => 'desc', 'name' => 'created'],
        ],
    ])
        ->and($json)
        ->not->toContain('state')
        ->not->toContain('full_name')
        ->not->toContain('created_at');
});

it('honors per-search limit overrides', function (): void {
    $configure = fn (Search $search): Search
        => $search->maxLength(2_000)->maxDepth(8)->maxClauses(60);

    expect(Product::query()->whereSearch(str_repeat('a', 1_001), $configure)->count())->toBe(0)
        ->and(
            Product::query()
                ->whereSearch(str_repeat('(', 6).'name:ana'.str_repeat(')', 6), $configure)
                ->count(),
        )->toBe(
            0,
        )
        ->and(
            Product::query()
                ->whereSearch(implode(' ', array_fill(0, 51, 'name:ana')), $configure)
                ->count(),
        )->toBe(
            0,
        );
});

it('keeps explicit and default sorts independent of denied fields', function (): void {
    SortingFixtures::seed();

    $search = Search::make()->fields([
        Field::integer('quantity')->authorize(static fn (): bool => false),
        Field::integer('rank')->column('meta->rank')->authorize(static fn (): bool => false),
    ])->sorts([
        'quantity',
        AllowedSort::field('amount', 'quantity'),
        AllowedSort::field('rank', 'meta->rank'),
    ])->defaultSort('amount-desc');

    Product::query()->whereKey(1)->update(['meta' => ['rank' => 9]]);
    Product::query()->whereKey(3)->update(['meta' => ['rank' => 2]]);
    Product::query()->whereKey(4)->update(['meta' => ['rank' => 5]]);

    foreach ([
        '' => [1, 4, 3],
        'sort:quantity-asc' => [3, 4, 1],
        'sort:rank-asc' => [3, 4, 1],
    ] as $input => $expected) {
        expect(Product::query()->tap($search->query($input))->pluck('id')->take(3)->all())
            ->toBe($expected);
    }
});

it('leaves the builder untouched when parsing produces no filters', function (): void {
    ColumnFixtures::seed();

    $builder = Product::query()->where('tenant_id', 1);
    $sql = $builder->toSql();
    $search = Search::make()->fields([Field::string('status')->ignore('any')]);

    foreach ([null, '', '   ', 'status:any'] as $query) {
        $builder->tap($search->query($query));

        expect($builder->toSql())->toBe($sql)->and($builder->getBindings())->toBe([1]);
    }
});

it('omits unauthorized directive defaults and skips all denied term fields', function (): void {
    AuthorizationFixtures::seed();

    $called = false;
    $search = Search::make()->fields([
        Field::integer('limit')->directive()->default(1)->authorize(static fn (): bool => false)
            ->using(function () use (&$called): void {
                $called = true;
            }),
        Field::string('name')->authorize(static fn (): bool => false),
    ])->terms(['name']);

    expect(Product::query()->tap($search)->count())->toBe(2)
        ->and(Product::query()->tap($search->query('coffee'))->count())->toBe(0)
        ->and(Product::query()->tap($search->query('-coffee'))->count())->toBe(2)
        ->and($called)->toBeFalse();
});

it('resolves the time zone closure once per invocation and exposes a shared immutable clock', function (): void {
    DateFixtures::seed();

    Date::setTestNow(CarbonImmutable::parse('2025-01-16T02:00:00.123456Z'));

    $zones = 0;
    $contexts = [];
    $resolver = function (Builder $query, Clause $clause, Context $context) use (&$contexts): void {
        $contexts[] = $context;

        Date::setTestNow(CarbonImmutable::parse('2025-02-01T12:00:00Z'));

        $bounds = DateValue::parse((string) $clause->value, $context->timezone, $context->now);

        $query->where(
            'created_at',
            '>=',
            $bounds->start()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    };
    $search = DateFixtures::search(
        'created:today created:yesterday',
        function () use (&$zones): DateTimeZone {
            $zones++;

            return new DateTimeZone('America/Managua');
        },
        [Field::date('created')->using($resolver)],
    );

    expect(Product::query()->where('tenant_id', 1)->tap($search)->count())->toBe(3)
        ->and($zones)->toBe(1)
        ->and($contexts[0]->timezone->getName())->toBe('America/Managua')
        ->and($contexts[0]->now)->toBe($contexts[1]->now)
        ->and($contexts[0]->now->format('Y-m-d H:i:s.u'))->toBe('2025-01-16 02:00:00.123456');

    Product::query()->tap($search);

    expect($zones)->toBe(2);
});
