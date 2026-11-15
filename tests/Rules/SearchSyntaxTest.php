<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Rules\SearchSyntax;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\SearchProductsRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Product;

beforeEach(function (): void {
    Route::post(
        '/validate-search',
        static fn (SearchProductsRequest $request): array => $request->validated(),
    );
});

it('retains translated messages and propagates application configuration failures', function (): void {
    Lang::addLines(
        [
            'errors.field.invalid_value' => 'Invalid :field.',
            'errors.position' => 'Offset :position.',
        ],
        'en',
        'search-syntax-parser',
    );

    $rule = new SearchSyntax(Product::class);
    $validator = Validator::make(['filter' => 'quantity:nope'], ['filter' => [$rule]]);

    try {
        Product::query()->whereSearch('quantity:nope');

        test()->fail('Expected invalid quantity.');
    } catch (ValidationException $validationException) {
        expect($validator->errors()->get('filter'))->toBe($validationException->errors()['q']);
    }

    $bad = new SearchSyntax(
        Product::class,
        static fn (): Search => Search::make()->fields([Field::trashed()]),
    );

    expect(
        fn () => Validator::make(['filter' => 'trashed:with'], ['filter' => [$bad]])->passes(),
    )->toThrow(
        LogicException::class,
    )
        ->and(fn (): SearchSyntax => new SearchSyntax(Customer::class))->toThrow(
            InvalidArgumentException::class,
        );
});

it('returns the same translated search message under the validated attribute', function (
    mixed $input,
): void {
    $rule = new SearchProductsRequest()->rules()['filter'][0];
    $messages = [];

    $rule->validate('filter', $input, static function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    test()
        ->postJson('/validate-search', ['filter' => $input])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('filter')
        ->assertJsonPath('errors.filter', $messages);

    expect($messages)->not->toBeEmpty();
})->with([
    ['unknown:value'], ['secret:value'], ['name:"broken'], ['created:2024-02-30'], [
        'NOT trashed:only',
    ], [
        'sort:missing',
    ], [
        'name:a name:b name:c',
    ], [
        str_repeat('a', 101),
    ], [
        '(((name:a)))',
    ], [
        ['name:coffee'],
    ], [
        false,
    ],
]);

it('uses the configure closure contract for mutation return and replacement', function (): void {
    $callbacks = [
        static function (Search $search): void {
            $search->fields([Field::integer('number', 'quantity')])->terms([]);
        },
        static fn (Search $search): Search
            => $search->fields([Field::integer('number', 'quantity')])->terms([]),
        static fn (): Search => Search::make()->fields([Field::integer('number', 'quantity')]),
    ];

    foreach ($callbacks as $configure) {
        $rule = new SearchSyntax(Product::class, $configure);

        expect(
            Validator::make(['filter' => 'number:10'], ['filter' => [$rule]])->passes(),
        )->toBeTrue()
            ->and(
                Validator::make(['filter' => 'name:coffee'], ['filter' => [$rule]])->fails(),
            )->toBeTrue();
    }
});

it('applies defaults on missing and empty attributes without executing the directive query', function (): void {
    foreach ([[], ['filter' => null], ['filter' => ''], ['filter' => '   ']] as $input) {
        $applied = false;
        $rule = new SearchSyntax(Product::class, static function () use (&$applied): Search {
            return Search::make()->fields([
                Field::integer('take')->directive()->default(5)->using(
                    function ($query, $clause) use (&$applied): void {
                        $applied = true;

                        $query->limit($clause->value);
                    },
                ),
            ]);
        });

        expect(Validator::make($input, ['filter' => [$rule]])->passes())->toBeTrue()
            ->and($applied)->toBeTrue();
    }
});

it('validates search input from a form request without executing SQL', function (
    mixed $input,
): void {
    DB::enableQueryLog();

    test()->postJson('/validate-search', ['filter' => $input])->assertOk();

    expect(DB::getQueryLog())->toBeEmpty();
})->with([
    ['name:coffee'],
    ['trashed:only sort:name-desc'],
    ['created:2024-02-29'],
    [null],
    [''],
    ['   '],
]);
