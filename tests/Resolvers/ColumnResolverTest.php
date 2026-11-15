<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Resolvers\ColumnResolver;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ColumnFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\ScopeFixtures;
use HosmelQ\SearchSyntaxParser\Query\Bounds;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\Product;

it('checks presence booleans and their lists with plain NULL checks', function (): void {
    ScopeFixtures::seed();

    $field = Field::presence('archived', 'archived_at');
    $query = Product::query()
        ->where('tenant_id', 1)
        ->tap(Search::make()->fields([$field])->query('-archived:false'));

    expect($query->orderBy('id')->pluck('id')->all())->toBe([1, 4])
        ->and(mb_substr_count(mb_strtolower($query->toSql()), 'select'))->toBe(1)
        ->and(ScopeFixtures::ids('archived:true,false', $field))->toBe([1, 2, 3, 4, 5])
        ->and(ScopeFixtures::ids('archived:!=true,false', $field))->toBeEmpty()
        ->and(ScopeFixtures::ids('NOT archived:true', $field))->toBe([2, 3, 5]);
});

it('derives range direction from its operator for custom resolver clauses', function (
    Bounds|Operator $bounds,
    array $ids,
): void {
    ColumnFixtures::seed();

    $query = Product::query()->where('tenant_id', 1);

    $query->where(function (Builder $group) use ($bounds): void {
        new ColumnResolver()->apply(
            $group,
            new Clause(
                'quantity',
                $bounds instanceof Operator ? $bounds : Operator::NotBetween,
                $bounds instanceof Operator ? null : $bounds,
            ),
            new Context(
                Field::integer('quantity'),
                $group->getModel()->getConnection()->getDriverName(),
                new DateTimeZone('UTC'),
                CarbonImmutable::parse('2025-01-15T12:00:00Z'),
            ),
        );
    });

    expect($query->orderBy('id')->pluck('id')->all())->toBe($ids);
})->with([
    [new Bounds(10, 20), [1, 5]],
    [new Bounds(null, 10), [3, 5]],
    [new Bounds(20, null), [1, 2]],
    [Operator::NotExists, [4]],
]);

it('resolves column alternatives expressions and missing values with their complements', function (
    string $input,
    array $ids,
): void {
    ColumnFixtures::seed();

    expect(ColumnFixtures::ids($input))->toBe($ids);
})->with([
    'both open' => ['quantity:*..*', [1, 2, 3, 5]],
    'not both open' => ['-quantity:*..*', [4]],
    'exists' => ['quantity:*', [1, 2, 3, 5]],
    'missing' => ['-quantity:*', [4]],
    'multiple columns' => ['person:ANA', [1, 4]],
    'multiple columns list' => ['person:ana,diaz', [1, 3, 4]],
    'multiple columns complement' => ['person:!=ana', [2, 3, 5]],
    'multiple columns not list' => ['person:!=ana,diaz', [2, 5]],
    'multiple columns exists' => ['person:*', [1, 2, 3, 4]],
    'multiple columns absent' => ['-person:*', [5]],
    'expression' => ['full_name:"ANA SILVA"', [1]],
    'expression complement' => ['-full_name:"ana silva"', [2, 3, 4, 5]],
]);
