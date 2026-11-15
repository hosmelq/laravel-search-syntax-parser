<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AggregateFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AuthorizationFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\DateFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\FullTextFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\JsonFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\RelationFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ScopeFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\SortingFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Status;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;

it('cannot reenable ordering after selecting scope predicates', function (): void {
    $field = Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue']);

    expect(fn (): Field => $field->operators('=', '>'))
        ->toThrow(LogicException::class, 'scopes() allows only the = and != operators.')
        ->and($field->jsonSerialize()['operators'])->toBe(['=', '!='])
        ->and(fn (): array => ScopeFixtures::ids('is:>overdue', $field))
        ->toThrow(ValidationException::class);
});

it('checks directive configuration before running an empty search', function (): void {
    SortingFixtures::seed();

    expect(fn () => Product::query()->tap(Search::make()->fields([Field::trashed()])))
        ->toThrow(LogicException::class, 'SoftDeletes')
        ->and(
            fn ()
                => Product::query()
                    ->tap(Search::make()->fields([Field::integer('take')->directive()])),
        )
        ->toThrow(LogicException::class, 'using()');
});

it('checks the configuration of unauthorized fields on every search', function (
    Closure $field,
): void {
    AuthorizationFixtures::seed();

    Product::query()->tap(Search::make()->fields([
        Field::string('name'),
        $field()->authorize(static fn (): bool => false),
    ]))->get();
})->throws(LogicException::class)->with([
    fn (): Field => Field::string('directive')->directive(),
    Field::trashed(...),
]);

it('keeps denied fields in name resolution so broader patterns cannot accept their names', function (): void {
    AuthorizationFixtures::seed();

    Product::query()
        ->whereKey(1)
        ->update(['meta' => ['rank' => 9, 'color' => 'red', 'secret' => ['x' => 'y']]]);

    $denied = static fn (): bool => false;
    $search = Search::make()->fields([
        Field::string('meta.*')->keys(static fn (string $key): bool => true)->column('meta->*'),
        Field::integer('meta.rank', 'meta->rank')->authorize($denied),
        Field::string('meta.secret.*')
            ->keys(static fn (string $key): bool => true)
            ->column('meta->secret->*')
            ->authorize($denied),
    ]);

    expect(Product::query()->tap($search->query('meta.color:red'))->pluck('id')->all())->toBe([1]);

    foreach (['meta.rank:9' => 0, 'meta.secret.x:y' => 0] as $input => $position) {
        try {
            Product::query()->tap($search->query($input))->get();

            test()->fail('Expected an unknown field error.');
        } catch (ValidationException $validationException) {
            expect($validationException->errors()['q'][0])
                ->toContain('Allowed fields: meta.*.')
                ->toContain('position '.$position);
        }
    }
});

it('rejects empty column targets', function (): void {
    expect(fn (): Field => Field::string('name')->columns([]))->toThrow(
        InvalidArgumentException::class,
    )
        ->and(fn (): Field => Field::string('name')->relation('lines', []))->toThrow(
            InvalidArgumentException::class,
        );
});

it('rejects every invalid scope or predicate configuration', function (Closure $configure): void {
    ScopeFixtures::seed();

    expect(fn () => Product::query()->tap(Search::make()->fields([$configure()])))->toThrow(
        LogicException::class,
    );
})->with([
    fn (): Field => Field::string('is')->scopes(['overdue' => 'overdue']),
    fn (): Field => Field::integer('is')->scopes([1 => 'overdue']),
    fn (): Field => Field::in('is', ['overdue', 'ready'])->scopes(['overdue' => 'overdue']),
    fn (): Field
        => Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue', 'extra' => 'ready']),
    fn (): Field => Field::enum('is', Status::class)->scopes(['open' => 'overdue']),
    fn (): Field => Field::boolean('is')->scopes([]),
    fn (): Field => Field::presence('is', 'created_at')->scopes([true => 'overdue']),
    fn (): Field => Field::boolean('is')->scopes(['extra' => 'overdue']),
    fn (): Field
        => Field::boolean('is')->scopes([true => 'overdue', false => 'ready', 'extra' => 'ready']),
    fn (): Field => Field::in('is', ['overdue'])->scopes(['overdue' => '']),
    fn (): Field => Field::in('is', ['overdue'])->scopes(['overdue' => 'unknown']),
    fn (): Field => Field::in('is', ['overdue'])->scopes(['overdue' => null]),
    fn (): Field => Field::in('is', ['overdue'])->scopes(['overdue' => 10]),
    fn (): Field => Field::in('is', ['overdue'])->scopes(['overdue' => ['overdue']]),
    fn (): Field
        => Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue', 'OVERDUE' => 'ready']),
    fn (): Field => Field::in('is', [])->scopes([]),
    fn (): Field => Field::in('is', [null])->scopes([]),
    fn (): Field
        => Field::in('is', ['overdue'])->column('quantity')->scopes(['overdue' => 'overdue']),
    fn (): Field
        => Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue'])->column('quantity'),
    fn (): Field
        => Field::in('is', ['overdue'])
            ->expression(fn (): Expression => new Expression('1'))
            ->scopes(['overdue' => 'overdue']),
    fn (): Field
        => Field::in('is', ['overdue'])
            ->scopes(['overdue' => 'overdue'])
            ->expression(fn (): Expression => new Expression('1')),
    fn (): Field
        => Field::in('is', ['overdue'])->jsonArray('meta')->scopes(['overdue' => 'overdue']),
    fn (): Field
        => Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue'])->jsonArray('meta'),
    fn (): Field
        => Field::in('is', ['overdue'])->relation('lines')->scopes(['overdue' => 'overdue']),
    fn (): Field
        => Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue'])->relation('lines'),
    fn (): Field
        => Field::in('is', ['overdue'])
            ->using(static function (): void {})
            ->scopes(['overdue' => 'overdue']),
    fn (): Field
        => Field::in('is', ['overdue'])
            ->scopes(['overdue' => 'overdue'])
            ->using(static function (): void {}),
    fn (): Field => Field::integer('is')->countOf('lines')->scopes([0 => 'overdue']),
    fn (): Field => Field::boolean('is')->scopes([true => 'overdue'])->countOf('lines'),
    fn (): Field => Field::presence('is', 'archived_at')->whenEmpty(0),
]);

it('rejects incompatible relation targets in both setter orders', function (string $method): void {
    RelationFixtures::seed();

    $configure = match ($method) {
        'jsonArray' => static fn (Field $field): Field => $field->jsonArray('meta'),
        'expression' => static fn (Field $field): Field
            => $field->expression(static fn (): Expression => new Expression('name')),
        'using' => static fn (Field $field): Field => $field->using(static function (): void {}),
    };

    expect(fn (): Field => $configure(Field::string('name')->relation('lines')))->toThrow(
        LogicException::class,
    )
        ->and(fn (): Field => $configure(Field::string('name'))->relation('lines'))->toThrow(
            LogicException::class,
        );
})->with(['jsonArray', 'expression', 'using']);

it('rejects invalid aggregate configurations before even an empty search', function (
    Closure $configure,
): void {
    AggregateFixtures::seed();

    expect(fn () => Product::query()->tap(Search::make()->fields([$configure()])))->toThrow(
        LogicException::class,
    );
})->with([
    fn (): Field => Field::numeric('value')->countOf('lines'),
    fn (): Field => Field::integer('value')->sumOf('lines', 'quantity'),
    fn (): Field => Field::date('value')->avgOf('lines', 'quantity'),
    fn (): Field => Field::string('value')->minOf('lines', 'name'),
    fn (): Field => Field::boolean('value')->maxOf('lines', 'quantity'),
    fn (): Field => Field::numeric('value')->sumOf('lines.product', 'quantity'),
    fn (): Field => Field::numeric('value')->sumOf('', 'quantity'),
    fn (): Field => Field::numeric('value')->maxOf('lines', ''),
    fn (): Field => Field::integer('value')->countOf('subject'),
    fn (): Field => Field::integer('value')->countOf('unknown'),
    fn (): Field => Field::numeric('value')->whenEmpty(0),
    fn (): Field => Field::integer('value')->countOf('lines')->whenEmpty(0),
    fn (): Field => Field::numeric('value')->sumOf('lines', 'quantity')->whenEmpty('invalid'),
    fn (): Field => Field::date('value', 'd/m/Y')->minOf('lines', 'created_at'),
    fn (): Field => Field::numeric('value.*')->keys('/^[a-z]+$/')->sumOf('lines', 'quantity'),
    fn (): Field => Field::array('value')->sumOf('lines', 'quantity'),
    fn (): Field => Field::numeric('value')->column('quantity')->sumOf('lines', 'quantity'),
    fn (): Field => Field::numeric('value')->sumOf('lines', 'quantity')->column('quantity'),
    fn (): Field
        => Field::numeric('value')
            ->expression(fn (): Expression => new Expression('1'))
            ->sumOf('lines', 'quantity'),
    fn (): Field
        => Field::numeric('value')
            ->sumOf('lines', 'quantity')
            ->expression(fn (): Expression => new Expression('1')),
    fn (): Field => Field::numeric('value')->jsonArray('meta->values')->sumOf('lines', 'quantity'),
    fn (): Field => Field::numeric('value')->sumOf('lines', 'quantity')->jsonArray('meta->values'),
    fn (): Field => Field::numeric('value')->relation('lines')->sumOf('lines', 'quantity'),
    fn (): Field => Field::numeric('value')->sumOf('lines', 'quantity')->relation('lines'),
    fn (): Field
        => Field::numeric('value')->using(static function (): void {})->sumOf('lines', 'quantity'),
    fn (): Field
        => Field::numeric('value')->sumOf('lines', 'quantity')->using(static function (): void {}),
    fn (): Field => Field::numeric('value')->sumOf('lines', 'quantity')->avgOf('lines', 'quantity'),
]);

it('rejects invalid JSON and dynamic field configurations', function (
    Field $field,
    string $message,
): void {
    expect(fn (): array => JsonFixtures::ids('', [$field]))->toThrow(
        LogicException::class,
        $message,
    );
})->with([
    [Field::date('created')->column('meta->created'), 'created'],
    [Field::date('created')->jsonArray('tags'), 'created'],
    [Field::date('created')->columns(['created_at', 'meta->created']), 'created'],
    [Field::string('meta.*')->keys('/.*/'), 'meta.*'],
    [Field::string('meta.*')->keys('/.*/')->column('name'), 'meta.*'],
    [Field::string('meta.*')->keys('/.*/')->column('meta->value'), 'meta.*'],
    [Field::string('meta.*')->keys('/.*/')->column('meta->*->*'), 'meta.*'],
    [Field::string('meta.*')->keys('/.*/')->column('*->value'), 'meta.*'],
    [Field::string('meta.*')->keys('/.*/')->jsonArray('tags'), 'meta.*'],
    [
        Field::string('meta.*')
            ->keys('/.*/')
            ->expression(static fn (): Expression => new Expression('name')),
        'meta.*',
    ],
    [Field::string('value')->jsonArray('tags')->directive(), 'requires using()'],
    [Field::string('value')->column('meta->value')->words(), 'plain column targets'],
    [Field::string('value')->jsonArray('tags')->words(), 'plain column targets'],
]);

it('rejects invalid relation field configurations before an empty search', function (
    Closure $field,
): void {
    RelationFixtures::seed();

    expect(fn (): array => RelationFixtures::ids('', [$field()]))->toThrow(LogicException::class);
})->with([
    'empty path' => [static fn (): Field => Field::string('name')->relation('')],
    'empty segment' => [static fn (): Field => Field::string('name')->relation('lines..member')],
    'SQL path' => [static fn (): Field => Field::string('name')->relation('lines->member')],
    'invalid type' => [
        static fn (): Field => Field::string('name')->relation('subject', types: [stdClass::class]),
    ],
    'wildcard type' => [
        static fn (): Field => Field::string('name')->relation('subject', types: ['*']),
    ],
    'date JSON' => [
        static fn (): Field => Field::date('created')->relation('lines', 'meta->created'),
    ],
    'custom date format' => [
        static fn (): Field => Field::date('created', 'd/m/Y')->relation('lines'),
    ],
    'array' => [static fn (): Field => Field::array('name')->relation('lines')],
]);

it('rejects storage conversions that change scope values', function (Closure $field): void {
    ScopeFixtures::seed();

    Product::query()->tap(Search::make()->fields([$field()])->query(''))->get();
})->throws(LogicException::class, 'scopes() cannot be combined with map() or scale().')->with([
    fn (): Field
        => Field::in('s', ['a', 'b'])
            ->scopes(['a' => 'overdue', 'b' => 'ready'])
            ->map(['a' => 'x', 'b' => 'x']),
    fn (): Field => Field::in('s', [1, 2])->map([1 => 10])->scopes([1 => 'overdue', 2 => 'ready']),
    fn (): Field => Field::boolean('s')->scopes([true => 'overdue'])->map([1 => 'yes']),
]);

it('rejects unsafe dynamic keys with positioned validation errors', function (string $key): void {
    $field = Field::string('meta.*')
        ->keys(static fn (string $key): bool => true)
        ->column('meta->*');
    $input = '  meta.'.$key.':php';

    expect($field->accepts('meta.'.$key))->toBeFalse();

    try {
        JsonFixtures::ids($input, [$field]);

        test()->fail('Expected a positioned validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['q'][0])->toContain('At position');
    }
})->with(['x->y', 'x"y', 'x\\y', 'x[y', 'x]y', "x\0y", "x\ny", "x\x7fy"]);

it('requires a key rule for dynamic fields', function (): void {
    expect(fn () => Product::query()->tap(Search::make()->fields([
        Field::string('meta.*')->column('meta->*'),
    ])))->toThrow(InvalidArgumentException::class, 'keys()');
});

it('requires an explicit SQLite fallback even for an empty search', function (): void {
    FullTextFixtures::seed();

    if (Product::query()->getModel()->getConnection()->getDriverName() !== 'sqlite') {
        expect(
            Product::query()
                ->tap(Search::make()->fields([Field::string('name')->words()]))
                ->count(),
        )->toBe(
            6,
        );

        return;
    }

    foreach (['', 'name:coffee', 'coffee'] as $input) {
        expect(
            fn ()
                => Product::query()
                    ->tap(Search::make()->fields([Field::string('name')->words()])->query($input)),
        )->toThrow(LogicException::class, 'likeFallback()');
    }

    expect(FullTextFixtures::ids('text:coffee'))->toBe([1, 2, 3, 6]);
})->group('full-text');

it('requires custom resolvers for formatted dates', function (): void {
    DateFixtures::seed();

    expect(
        fn () => Product::query()->tap(Search::make()->fields([Field::date('birthday', 'd/m/Y')])),
    )->toThrow(
        LogicException::class,
        'birthday',
    );

    $search = Search::make()
        ->fields([Field::date('birthday', 'd/m/Y')->using(function (Builder $query): void {
            $query->whereRaw('1 = 0');
        })])
        ->query('birthday:15/01/2025');

    expect(Product::query()->tap($search)->count())->toBe(0);
});

it('requires date whenEmpty() values in the stored date format', function (): void {
    AggregateFixtures::seed();

    expect(fn (): Field => Field::date('value')->minOf('lines', 'created_at')->whenEmpty(5))
        ->toThrow(LogicException::class, 'A date aggregate requires a whenEmpty() date string.');

    foreach (['garbage', '2025-02-30 00:00:00', '2025-01-15'] as $empty) {
        $field = Field::date('value')->minOf('lines', 'created_at')->whenEmpty($empty);

        expect(fn (): array => AggregateFixtures::ids('value:2025-01-15', $field))
            ->toThrow(LogicException::class, 'requires a date in the format');
    }

    $dateOnly = Field::date('value')
        ->minOf('lines', 'available_on')
        ->withoutTime()
        ->whenEmpty('2025-01-15');

    expect(AggregateFixtures::ids('value:2025-01-15', $dateOnly))->toBeArray();
});

it('accepts an expression supplied for the selected driver', function (): void {
    ColumnFixtures::seed();

    $configure = fn (Search $search): Search => $search->fields([
        Field::integer('double')->expression(
            fn (string $driver): Expression => new Expression('quantity * 2'),
        ),
    ])->terms([]);

    expect(ColumnFixtures::ids('double:40', $configure))->toBe([3]);
});

it('checks string abilities with Gate including guest policies', function (): void {
    Gate::define(
        'search-status',
        static fn (null|Authenticatable $user): bool => $user?->getAuthIdentifier() === 1,
    );
    Gate::define(
        'search-public',
        static fn (null|Authenticatable $user): bool => ! $user instanceof Authenticatable,
    );

    $status = Field::string('status')->authorize('search-status');
    $name = Field::string('name')->authorize('search-public');

    expect($status->isAuthorized())->toBeFalse()->and($name->isAuthorized())->toBeTrue();

    Auth::setUser(new GenericUser(['id' => 1]));

    expect($status->isAuthorized())->toBeTrue()->and($name->isAuthorized())->toBeFalse();
});

it('evaluates closures with the current user on every search invocation', function (): void {
    AuthorizationFixtures::seed();

    $users = [];
    $search = Search::make()->fields([
        Field::string('status')->authorize(
            function (null|Authenticatable $user) use (&$users): bool {
                $users[] = $user;

                return $user?->getAuthIdentifier() === 1;
            },
        ),
    ]);

    expect(fn () => Product::query()->tap($search->query('status:open'))->count())
        ->toThrow(ValidationException::class);

    $user = new GenericUser(['id' => 1]);

    Auth::setUser($user);

    expect(Product::query()->tap($search)->pluck('id')->all())->toBe([1])
        ->and($users)->toBe([null, $user]);
});

it('lets the last target setter replace jsonArray configuration', function (): void {
    JsonFixtures::rows(['PHP']);

    expect(
        JsonFixtures::ids(
            'value:php',
            [Field::string('value')->jsonArray('tags')->column('meta->value')],
        ),
    )->toBe(
        [1],
    )
        ->and(
            JsonFixtures::ids(
                'value:php',
                [Field::string('value')->jsonArray('tags')->columns(['meta->value'])],
            ),
        )->toBe(
            [1],
        )
        ->and(
            JsonFixtures::ids(
                'value:php',
                [
                    Field::string('value')
                        ->jsonArray('tags')
                        ->expression(static fn (): Expression => new Expression("'PHP'")),
                ],
            ),
        )->toBe(
            [1, 2, 3],
        )->and(
            JsonFixtures::ids(
                '',
                [
                    Field::date('created', internalName: 'meta->created')->expression(
                        static fn (): Expression => new Expression('created_at'),
                    ),
                ],
            ),
        )->toBe(
            [1, 2, 3],
        );
});

it('uses internal names and explicit qualified columns without ambiguity', function (): void {
    ColumnFixtures::seed();

    $search = Search::make()->fields([Field::string('label', 'name')])->query('label:"Ana Silva"');
    $builder = Product::query()
        ->join('products as peers', 'peers.id', '=', 'products.id')
        ->tap($search);

    expect($builder->pluck('products.id')->all())->toBe([1]);

    $search->fields([Field::string('label')->column('peers.name')]);

    expect(
        Product::query()
            ->join('products as peers', 'peers.id', '=', 'products.id')
            ->tap($search)
            ->pluck('products.id')
            ->all(),
    )->toBe(
        [1],
    );
});
