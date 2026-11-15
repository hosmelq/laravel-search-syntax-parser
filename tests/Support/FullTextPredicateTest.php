<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Support\ColumnTarget;
use HosmelQ\SearchSyntaxParser\Laravel\Support\FullTextPredicate;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\FullTextFixtures;
use HosmelQ\SearchSyntaxParser\Support\FullText;
use Illuminate\Database\MariaDbConnection;
use Workbench\App\Models\Product;

it('compiles MariaDB full-text with only sanitized word bindings', function (): void {
    FullTextFixtures::seed();

    $connection = Product::query()->getModel()->getConnection();
    $maria = new MariaDbConnection($connection->getPdo());
    $predicate = new FullTextPredicate(
        new ColumnTarget('products.name'),
        new FullText('coffee|ceramic'),
        new Context(
            Field::string('name')->words(),
            'mariadb',
            new DateTimeZone('UTC'),
            CarbonImmutable::now(),
        ),
    );

    expect($predicate->getValue($maria->getQueryGrammar()))->toContain(
        'MATCH (`products`.`name`) AGAINST (? IN BOOLEAN MODE)',
    )
        ->and($predicate->bindings())->toBe(['+coffee +ceramic']);
})->group('full-text');

it('matches unquoted multi-word values in any order without exposing full-text operators', function (): void {
    FullTextFixtures::seed();

    foreach ([
        'coffee-ceramic',
        '+coffee+ceramic',
        'coffee|ceramic',
        'coffee&ceramic',
        'coffee%_!ceramic',
        'coffee@ceramic',
        'coffee~ceramic',
    ] as $value) {
        $query = Product::query()->tap(Search::make()->fields([
            Field::string('text', 'description')->words()->likeFallback(),
        ])->query('text:'.$value));

        expect($query->orderBy('id')->pluck('id')->all())->toBe(
            Product::query()->getModel()->getConnection()->getDriverName() === 'sqlite'
                ? [1, 2, 3, 6]
                : [1, 2, 6],
        );
    }

    expect(FullTextFixtures::ids('text:"coffee | espresso"'))->toBeEmpty()
        ->and(FullTextFixtures::ids('text:"coffee \' ceramic"'))->toBe(
            Product::query()->getModel()->getConnection()->getDriverName() === 'sqlite'
                ? [1, 2, 3, 6]
                : [1, 6],
        );
})->group('full-text');

it('matches words phrases prefixes lists and their NULL-aware complements', function (): void {
    FullTextFixtures::seed();

    $sqlite = Product::query()->getModel()->getConnection()->getDriverName() === 'sqlite';

    foreach ([
        ['text:coffee', $sqlite ? [1, 2, 3, 6] : [1, 2, 6]],
        ['text:"coffee*"', $sqlite ? [1, 2, 3, 6] : [1, 2, 6]],
        ['text:"coffee ceramic"', $sqlite ? [1, 2, 3, 6] : [1, 6]],
        ['text:"ceramic coffee"', $sqlite ? [1, 2, 3, 6] : []],
        ['text:"fresh coffee"', $sqlite ? [1, 2, 3] : [1, 2]],
        ['text:coff*', [1, 2, 3, 6]],
        ['text:"fresh coff"*', [1, 2, 3]],
        ['text:coffee,espresso', $sqlite ? [1, 2, 3, 5, 6] : [1, 2, 5, 6]],
        ['text:"coffee ceramic",espresso', $sqlite ? [1, 2, 3, 5, 6] : [1, 5, 6]],
        ['text:coff*,espresso', [1, 2, 3, 5, 6]],
        ['text:*', [1, 2, 3, 5, 6]],
        ['text:"!!!"', []],
    ] as [$input, $ids]) {
        $complement = array_values(array_diff(range(1, 6), $ids));

        expect(FullTextFixtures::ids($input))
            ->toBe($ids)
            ->and(FullTextFixtures::ids('NOT '.$input))
            ->toBe($complement);

        if ($input !== 'text:*') {
            expect(FullTextFixtures::ids(str_replace('text:', 'text:!=', $input)))->toBe(
                $complement,
            );
        }
    }
})->group('full-text');

it('uses a configured PostgreSQL language and its matching expression index', function (): void {
    FullTextFixtures::seed();

    $connection = Product::query()->getModel()->getConnection();

    if ($connection->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('PostgreSQL text search configurations.');
    }

    $connection->statement(
        "create index products_description_english on products using gin (to_tsvector('english', description))",
    );

    Product::query()->whereKey(5)->update(['description' => 'Running swiftly']);

    $query = Product::query()->tap(Search::make()->fields([
        Field::string('text', 'description')->words()->fullTextLanguage('english'),
    ])->query('text:run'));

    expect($query->pluck('id')->all())->toBe([5]);
})->group('full-text');
