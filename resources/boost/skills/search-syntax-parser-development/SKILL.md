---
name: search-syntax-parser-development
description: "Build Eloquent searches with hosmelq/laravel-search-syntax-parser, which turns validated query strings such as `status:open price:>10` into filters. Use when adding `whereSearch()` to a model, defining `Field` targets (columns, JSON, relations, aggregates, dates, full-text, scopes), configuring sorts, directives, or field authorization, describing fields for client autocomplete, validating search input in a form request, writing a custom resolver, or testing searches."
---

# Laravel Search Syntax Parser

A search is an allowlist of typed fields. The package parses a user's query against it and adds grouped WHERE clauses to an Eloquent builder. Invalid user input throws Laravel's `ValidationException`. Mistakes in the search definition throw exceptions such as `LogicException` and `InvalidArgumentException`, and are fixed in code.

Read the [full documentation](https://hosmelq.com/docs/laravel-search-syntax-parser) for behavior this skill leaves out. The query language comes from the [core package](https://hosmelq.com/docs/search-syntax-parser).

## Workflow

1. Define a typed field for every searchable value, adding `nullable()` when its column or expression can hold NULL. Use `HasSearchSyntax` on the model, or a `Search` object when the definition lives elsewhere.
2. Apply the search to a builder that already holds tenancy and record permissions. Users can omit search filters and override defaults.
3. Validate request input with the `SearchSyntax` rule when a form request receives it, and pass the same callback to the rule and the query.
4. Test a matching query, a negated query on a nullable field, and an invalid query for each new field.

## Classes

| Class | Namespace |
| --- | --- |
| `Field`, `Search`, `Context` | `HosmelQ\SearchSyntaxParser\Laravel` |
| `HasSearchSyntax` | `HosmelQ\SearchSyntaxParser\Laravel\Concerns` |
| `SearchSyntax` rule | `HosmelQ\SearchSyntaxParser\Laravel\Rules` |
| `Resolver` contract | `HosmelQ\SearchSyntaxParser\Laravel\Contracts` |
| `Clause` | `HosmelQ\SearchSyntaxParser\Query` |
| `AllowedSort` | `HosmelQ\SearchSyntaxParser\Sorting` |

## Define a search

```php
namespace App\Models;

use HosmelQ\SearchSyntaxParser\Laravel\Concerns\HasSearchSyntax;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasSearchSyntax;

    protected function searchSyntax(Search $search): Search
    {
        return $search
            ->fields([
                Field::string('name')->prefix(),
                Field::string('description')->nullable(),
                Field::in('status', ['open', 'pending', 'closed']),
                Field::integer('quantity')->min(0)->nullable(),
                Field::decimal('price', 0, 2)->column('price_cents')->scale(100),
            ])
            ->terms(['name', 'description']);
    }
}
```

Apply it with the `whereSearch()` scope. It accepts a string, a `Stringable`, or `null`, and empty input adds no filters:

```php
$products = Product::query()
    ->where('tenant_id', $tenantId)
    ->whereSearch($request->input('q'))
    ->paginate();
```

A callback as the second argument adjusts one call. `fields()` replaces the field allowlist and `terms()` replaces the term targets:

```php
$products = Product::query()
    ->whereSearch($request->input('q'), fn (Search $search): Search => $search->terms(['name']))
    ->get();
```

A `Search` object defined outside the model applies through `tap()`:

```php
$search = Search::make()
    ->fields([Field::string('name'), Field::integer('quantity')->nullable()])
    ->terms(['name'])
    ->query($request->input('q'));

$products = Product::query()->tap($search)->get();
```

## Query syntax

| Query | Meaning |
| --- | --- |
| `status:open` | Equality. String fields ignore case, and `prefix()` and `contains()` fields match unquoted text as a prefix or substring. |
| `price:>10`, `quantity:<=20`, `status:!=closed` | The operators `=`, `!=`, `>`, `>=`, `<`, and `<=`. |
| `status:open,pending` | Any listed value. `status:!=open,pending` matches none of them. |
| `price:[10 TO 20]`, `price:10..20`, `price:10..*` | An inclusive range. An unquoted `*` leaves an end open. |
| `description:*`, `-description:*` | A present value, or NULL. |
| `name:Ana*` | A wildcard pattern over the whole value. A quoted `"*"` is literal. |
| `(status:open OR status:pending) -quantity:0` | Groups with uppercase `AND`, `OR`, and `NOT`, or a leading minus. Whitespace means AND. |
| `coffee "tea cup"` | Free-text terms, matched against the fields named in `terms()`. |
| `sort:price-desc`, `trashed:with` | Sorts and directives, which reject OR and negation around them. |

Free-text terms match when any field in `terms()` matches, and separate terms must all match. Text fields match terms as substrings. Typed fields take part only when the term casts to their type, so `10` can match `quantity` while `coffee` skips it. The core validates term names even when denied or unused; unknown names, dates or shared date items, dynamic patterns, and directives raise `InvalidArgumentException`.

## Fields

The second argument of `string()`, `integer()`, `numeric()`, `boolean()`, `uuid()`, and `ulid()` is the internal name, which is also the column. `Field::decimal('price', 0, 2)` takes the allowed decimal places, `in()` its values, and `enum()` a backed enum class. `Field::date()` takes an input format second, so set a date column with `column()`.

| Target | Configuration |
| --- | --- |
| Another column | `->column('company_name')` |
| Any of several columns | `->columns(['first_name', 'last_name'])` |
| Trusted SQL expression | `->expression(fn (string $driver): Expression => new Expression('...'))` |
| JSON path | `->column('meta->color')` |
| JSON array of scalars | `->jsonArray('tags')` |
| Dynamic JSON keys | `Field::string('meta.*')->keys('/^[a-z_]+$/')->column('attributes->*')` matches `meta.color:red` |
| Related model | `->relation('customer', 'name')` or `->relation('lines.product', 'name')` |
| Path through `morphTo` | `->relation('comments.commentable', 'title', [Post::class, Video::class])` |
| Relation aggregate | `->countOf('lines')`, `->sumOf('lines', 'price_cents')`, `avgOf()`, `minOf()`, and `maxOf()` |
| Named predicates | `Field::in('is', ['overdue'])->scopes(['overdue' => 'overdue'])` |
| Boolean over a nullable column | `Field::presence('archived', 'archived_at')` |
| Custom SQL | `->using($resolver)`, described in [custom resolvers](#custom-resolvers) |

`column()`, `columns()`, `jsonArray()`, and `expression()` replace each other, and the last call wins. `countOf()` needs an integer field, `sumOf()` and `avgOf()` a numeric or decimal field, and `minOf()` and `maxOf()` a numeric, decimal, or date field. A scope field maps every allowed value to a local scope name or a closure.

| Behavior | Method |
| --- | --- |
| Include NULL in negations | `nullable()` |
| Prefix or substring text | `prefix()`, `contains()` |
| Words and phrases | `words()`, with `fullTextLanguage('english')` on PostgreSQL and `likeFallback()` on SQLite |
| Leading wildcards | `allowLeadingWildcards()`, `minWildcardLength(3)` |
| Convert to stored values | `scale(100)`, `map(['high' => 3, 'low' => 1])` |
| Limit values | `min()`, `max()`, `between()` |
| Limit clause shapes | `operators('=', '!=')`, `withoutLists()`, `withoutRanges()`, `withoutExists()`, `withoutWildcards()` |
| Calendar DATE columns | `withoutTime()`, and `allowPartialDates()` for years and months |
| Replace an empty aggregate | `whenEmpty(0)` |
| Include soft-deleted related rows | `withTrashedRelated()` |

The core field methods, such as `alias()`, `default()`, and `ignore()`, also apply.

## Dates

```php
$search
    ->fields([
        Field::date('created')->column('created_at')->allowPartialDates()->nullable(),
        Field::date('due')->column('due_on')->withoutTime()->nullable(),
    ])
    ->timezone(fn (): ?string => auth()->user()?->timezone);
```

`created:2025-01-15` covers that whole day in the search's time zone, which defaults to `config('app.timezone')`. Relative values such as `today`, `yesterday`, `now-7d`, and `today-1M` work in comparisons and ranges. The package expects stored datetimes in `config('app.timezone')`.

## Sorting and directives

```php
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;

$search
    ->fields([Field::string('name'), Field::trashed()])
    ->sorts([
        'name',
        AllowedSort::field('created', 'created_at')->defaultDirection('desc'),
    ])
    ->defaultSort('created-desc');
```

Users sort with `sort:created`, `sort:created-asc` or `sort:name-desc`, once per query. NULL values sort last. Sorts target columns and JSON paths. Add a tie-breaker such as `orderBy('id')` after the search for stable pagination.

`Field::trashed()` needs a model with `SoftDeletes` and accepts `trashed:with`, `trashed:only` and `trashed:without`, the default. A custom directive receives the root builder:

```php
$field = Field::integer('take')->min(1)->max(100)->directive()->default(25)
    ->using(function (Builder $query, Clause $clause, Context $context): void {
        $query->limit($clause->value);
    });
```

## Authorization

```php
use Illuminate\Contracts\Auth\Authenticatable;

$search->fields([
    Field::integer('cost')->authorize('search-cost'),
    Field::string('internal_note')->authorize(fn (null|Authenticatable $user): bool => $user !== null),
]);
```

An ability name calls `Gate::allows()`; closures receive `Auth::user()`. Both become a core authorization callback evaluated at most once per search invocation. Configuration checks include denied fields and their defaults. A denied field behaves like an unknown field, and its default and term target are skipped. Sorts are a separate allowlist: configure `sorts()` and `defaultSort()` for the current user, because ordering by a hidden column lets users estimate its values.

`Search` implements `JsonSerializable`. Return it from a route to describe its authorized fields and its sorts to a client, for example for autocomplete. Denied fields and database targets are never included.

## Form request validation

```php
use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Rules\SearchSyntax;

public function rules(): array
{
    return [
        'q' => [new SearchSyntax(Product::class)],
    ];
}
```

The rule compiles the model's search without running SQL and attaches messages to the validated attribute. Its optional second argument takes the same callback as `whereSearch()`. Fetch results with `whereSearch($request->validated('q'))`.

## Errors and limits

Search messages use the `error_key` option, `q` by default, or the key passed to `errorKey()`. They include zero-based source positions. The core rejects invalid request types with `query.invalid_type` and supplies `operation` for rejected operators or clause forms.

The `max_clauses` (50), `max_depth` (5), and `max_length` (1000) limits come from `config/search-syntax-parser.php` or the `SEARCH_SYNTAX_PARSER_MAX_CLAUSES`, `SEARCH_SYNTAX_PARSER_MAX_DEPTH`, and `SEARCH_SYNTAX_PARSER_MAX_LENGTH` variables. `maxClauses()`, `maxDepth()`, and `maxLength()` override them for one search.

`php artisan vendor:publish --tag=search-syntax-parser-translations` publishes the messages to `lang/vendor/search-syntax-parser`.

## Custom resolvers

```php
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;

$field = Field::integer('quantity')
    ->operators('=', '!=', '>', '>=', '<', '<=')
    ->withoutLists()->withoutRanges()->withoutExists()->withoutWildcards()
    ->nullable()
    ->using(function (Builder $query, Clause $clause, Context $context): void {
        $column = $query->qualifyColumn('quantity');

        $query->where($column, $clause->operator->value, $clause->value);

        if ($clause->negated && $context->field->isNullable()) {
            $query->orWhereNull($column);
        }
    });
```

The adapter translates the core's `SearchParser::compile()` clause tree. A resolver is a closure or a `Resolver` implementation. The clause holds the `operator`, already complemented for NOT, the storage `value`, the `negated` and `text` flags, and `fullText` word data. LIKE values are core `TextPattern` objects: bind `toLike()` with the matching SQL escape. `Clause::positive()` recovers a positive predicate. Restrict the field to the clause shapes the resolver handles, and add a predicate on every call, since an empty group adds no SQL. `$context->complement($query, $positive)` excludes every row a positive callback matches, including rows where SQL NOT yields NULL.

Handle date existence clauses directly. For other format-less date clauses, use the core's [date helpers](https://hosmelq.com/docs/search-syntax-parser/advanced-usage/compiling-clauses#resolve-date-intervals): `DateBounds::forClause()` resolves positive intervals and `toStorage()` converts their zone and precision. Apply complements and missing-value behavior in SQL.

## Testing

```php
use App\Models\Product;
use Illuminate\Validation\ValidationException;

it('rejects unknown fields', function (): void {
    expect(fn () => Product::query()->whereSearch('color:red'))
        ->toThrow(ValidationException::class);
});

it('includes null quantities in negations', function (): void {
    $missing = Product::factory()->create(['quantity' => null]);
    Product::factory()->create(['quantity' => 10]);

    expect(Product::query()->whereSearch('quantity:!=10')->pluck('id')->all())
        ->toBe([$missing->id]);
});
```

Freeze relative dates with `Date::setTestNow()`. InnoDB full-text indexes see only committed rows, so `words()` tests on MySQL and MariaDB need committed fixtures, for example with `DatabaseTruncation` instead of `RefreshDatabase`.

## Rules

- Build expressions, relation paths, scope names, and full-text configurations from application code only. User values reach SQL as bindings.
- Use `relation()` for related models. A dotted column such as `contacts.email` only names a table.
- Use `Field::string()` for numeric codes stored as text, such as ZIP codes.
- Add `likeFallback()` to `words()` fields when the application or its tests run on SQLite.
- Give each `words()` column its own FULLTEXT index on MySQL and MariaDB, or a GIN `to_tsvector` expression index with the same configuration on PostgreSQL.
- Use a single range such as `quantity:10..20` when both bounds must hold for the same related row or JSON array element.
