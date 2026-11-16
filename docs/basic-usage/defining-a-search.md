---
title: "Defining a Search"
description: "Add the whereSearch scope to a model, or apply a Search object to any Eloquent builder."
weight: 1
---

A search lists the fields users may query and applies their query to an Eloquent builder. Define it on the model, or build a `Search` object where your application needs it.

## Add the model scope

Use `HasSearchSyntax` and define the searchable fields in `searchSyntax()`:

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
                Field::decimal('price', 0, 2)->column('price_cents')->scale(100),
                Field::in('status', ['open', 'pending', 'closed']),
                Field::string('description')->nullable(),
            ])
            ->terms(['name', 'description']);
    }
}
```

Call `whereSearch()` on the model's query:

```php
use App\Models\Product;

$products = Product::query()
    ->where('tenant_id', $tenantId)
    ->whereSearch($request->input('q'))
    ->paginate();
```

The scope is named `whereSearch`, leaving Scout's `search()` method available. It accepts strings, `Stringable` values, and `null`. Other request input produces a validation error.

## Customize one call

Pass a callback as the second argument. It may change the supplied search, with or without returning it, or return a replacement:

```php
use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$products = Product::query()->whereSearch(
    $request->input('q'),
    fn (Search $search): Search => $search->terms(['name']),
)->get();
```

`fields()` replaces the field allowlist. `terms()` replaces the free-text targets and accepts public field names. See [configuration](../configuration) for limits and validation error keys.

## Use a search object

Create a `Search` directly when the definition belongs outside the model, then pass it to the builder's `tap()` method:

```php
use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$search = Search::make()
    ->fields([Field::string('name'), Field::integer('quantity')->nullable()])
    ->terms(['name', 'quantity'])
    ->query($request->input('q'));

$products = Product::query()->tap($search)->get();
```

## Describe a search

`Search` implements `JsonSerializable`. Return it from a route to send its fields and sorts to a client, for example for autocomplete:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use Illuminate\Support\Facades\Route;

Route::get('/products/search', fn (): Search => Search::make()
    ->fields([
        Field::string('name'),
        Field::integer('cost')->authorize('search-cost'),
    ])
    ->sorts(['name', AllowedSort::field('created', 'created_at')]));
```

The JSON is the core's [parser description](https://hosmelq.com/docs/search-syntax-parser/v3/basic-usage/directives-and-sorting/#describe-available-settings), without the fields the current user may not use. It has a `fields` list of [field descriptions](https://hosmelq.com/docs/search-syntax-parser/v3/basic-usage/field-validation-and-value-types/#describe-fields) and a `sorts` list with each sort's public `name` and default `direction`. Columns, JSON paths, relations, scopes, and other database targets are never included.

To describe a model's search, declare its `searchSyntax()` method public and return `new Product()->searchSyntax(Search::make())`.

## Filter collections

Use the core's [matcher](https://hosmelq.com/docs/search-syntax-parser/v3/basic-usage/matching-records/) with a collection's `filter()` method to search records already in memory:

```php
use HosmelQ\SearchSyntaxParser\Query\Matcher;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Validation\Field;

$parser = SearchParser::query('mug quantity:>1')->allowedFields([
    Field::string('name'),
    Field::integer('quantity'),
])->terms(['name']);

$matches = $products->filter(new Matcher($parser)->matches(...));
```

The matcher reads each record by the core field's internal name. It does not apply this package's columns, relations, scopes, aggregates, directives, or sorting, and invalid input throws the core's exceptions instead of validation errors.

## Keep required constraints on the builder

Search filters are grouped together, and each leaf has its own group. OR conditions therefore stay inside the search and do not bypass existing builder constraints or global scopes.

Apply tenancy and record permissions to the base builder. Search defaults are filters that users may override; they cannot enforce authorization.

`null`, empty strings, and whitespace add no filters unless defaults apply. Ignored clauses disappear during parsing. [Directives and sorts](directives-and-sorting) apply to the root builder, including when no filter clauses remain.
