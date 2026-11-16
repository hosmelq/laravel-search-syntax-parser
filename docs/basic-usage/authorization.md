---
title: "Field Authorization"
description: "Limit search fields to the users allowed to use them, and configure sorts for the current user."
weight: 14
---

A field can require an ability or a closure, so each user searches only the fields they may see. Record permissions stay on the builder.

## Authorize a field

Pass an ability name or a closure to `authorize()`:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Contracts\Auth\Authenticatable;

$search = Search::make()->fields([
    Field::string('name'),
    Field::integer('cost')->authorize('search-cost'),
    Field::string('internal_note')->authorize(
        fn (null|Authenticatable $user): bool => $user !== null,
    ),
]);
```

An ability name calls `Gate::allows()` without extra arguments. A closure receives `Auth::user()`, which may be `null`, and returns a boolean.

Each field's authorization is evaluated at most once per search invocation, through the core's [field authorization](https://hosmelq.com/docs/search-syntax-parser/v3/basic-usage/field-validation-and-value-types/#authorize-fields). Reusing a `Search` therefore evaluates permissions again for the current user.

## Exclude denied fields

A denied field behaves like an unknown field in user input. Its name is left out of the allowed-fields message, and its default and free-text target are skipped.

The field still reserves its names. A broader dynamic pattern cannot accept a denied exact field or a key claimed by a denied, more specific pattern. Application configuration is still checked, including invalid defaults and term targets on denied fields.

If no eligible term target remains, a positive term matches nothing and its complement matches everything. Authorization also applies to directives:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::trashed()->authorize('search-deleted');
```

## Configure sorts for the current user

Sorts are an explicit allowlist, like the core's `allowedSorts()`. Field authorization does not remove a sort with the same name or column.

A sort or `defaultSort()` on a denied field's column still orders rows by that hidden value. With pagination, a user can estimate the value from the positions of records. Applications must configure both `sorts()` and `defaultSort()` for the current user:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Support\Facades\Gate;

$search = Search::make()->sorts(['name'])->defaultSort('name-asc');

if (Gate::allows('sort-by-cost')) {
    $search->sorts(['name', 'cost'])->defaultSort('cost-desc');
}
```

## Apply record permissions separately

Field authorization controls which search features a user may request. Keep record permissions and tenancy constraints on the base builder or in global scopes. Users may omit optional filters and override defaults.
