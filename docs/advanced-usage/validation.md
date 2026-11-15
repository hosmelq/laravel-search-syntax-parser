---
title: "Form Request Validation"
description: "Validate search input in a form request against the model's search, without running the query."
weight: 2
---

The `SearchSyntax` rule checks search input in a form request with the same definition `whereSearch()` uses, and reports the same validation errors.

## Add the validation rule

Use `Rules\SearchSyntax` in a form request. The model must use `HasSearchSyntax`, directly or through a parent class:

```php
namespace App\Http\Requests;

use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Rules\SearchSyntax;
use Illuminate\Foundation\Http\FormRequest;

class SearchProductsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter' => [new SearchSyntax(Product::class)],
        ];
    }
}
```

The rule creates a fresh model query, applies `whereSearch()`, and compiles SQL without executing it. Typing, authorization, limits, dates, directives, sorting, and configuration checks follow the real search path.

Validation does not inspect database contents or verify that a full-text index exists. Run the query later to fetch results.

## Customize the search

The optional second argument has the same callback contract as `whereSearch()`. It may change the supplied search, with or without returning it, or return a replacement:

```php
use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Rules\SearchSyntax;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$rule = new SearchSyntax(
    Product::class,
    fn (Search $search): Search => $search->terms(['name']),
);
```

Apply the same configuration when using the validated value on the query that fetches results. See [configuration](../configuration) for query limits and per-search overrides.

## Handle empty and invalid input

The rule is implicit, so it runs for missing, `null`, and empty values. Values treated as empty searches pass, and defaults still apply to the temporary query. Non-string input follows the same rules as `whereSearch()`. A `Stringable` value is accepted when passed directly to a validator.

Search errors keep their translated text and source positions. They are attached to the validated attribute, including nested attributes such as `filters.q`, regardless of the search's error key.

Invalid model classes and application configuration errors remain exceptions.

## Apply the validated search

Validation neither enforces record permissions nor constrains the query used to fetch results. Apply the validated search to a builder with your normal tenancy and authorization constraints:

```php
use App\Models\Product;

$products = Product::query()
    ->where('tenant_id', $tenantId)
    ->whereSearch($request->validated('filter'))
    ->get();
```
