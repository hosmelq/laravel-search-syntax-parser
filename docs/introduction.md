---
title: "Introduction"
description: "Turn validated search queries into Eloquent filters on columns, JSON values, relations, and aggregates."
weight: 1
---

Laravel Search Syntax Parser turns search strings into Eloquent filters. Define the fields users can search, then apply their query to a model builder. Existing constraints and global scopes still apply.

Fields validate and convert input before it reaches SQL. They can target columns, JSON values, related models, aggregates, or application predicates. Date fields resolve calendar and relative dates in the user's time zone. Custom resolvers handle application-specific queries.

The query syntax supports quoted phrases, comparisons, lists, inclusive ranges, existence checks, wildcard patterns, and boolean groups. Only configured fields are accepted. Invalid syntax, unknown fields, and invalid values become Laravel validation errors.

## Quick start

After [installing the package](installation), define searchable fields and apply a query:

```php
use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$search = Search::make()
    ->fields([
        Field::string('name'),
        Field::integer('quantity')->nullable(),
    ])
    ->terms(['name'])
    ->query('coffee quantity:>0');

$products = Product::query()->tap($search)->get();
```

This finds products whose name contains `coffee` and whose quantity is greater than zero. Declare nullable columns with `nullable()` so negative filters include their missing values.

## Next steps

- [Defining a search](basic-usage/defining-a-search) connects fields and query input to a model.
- [Operators and values](basic-usage/operators-and-values) covers comparisons, lists, ranges, and boolean groups.
- [Field authorization](basic-usage/authorization) covers field permissions and sort allowlists for each user.
- [Directives and sorting](basic-usage/directives-and-sorting) configures query settings, soft deletes, and ordering.
- [Form request validation](advanced-usage/validation) validates a search without executing SQL.
