---
title: "Relations"
description: "Search attributes of related models through existence subqueries, including polymorphic relations."
weight: 9
---

Relation fields search attributes of related models. Each clause becomes an existence subquery, so the root query gets no joins.

## Search related models

Call `relation()` with an Eloquent relation path and target column:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::string('customer')->relation('customer', 'name'),
    Field::string('tag')->relation('tags', 'name'),
    Field::string('person')->relation('members', ['first_name', 'last_name']),
    Field::string('product')->relation('lines.product', 'name'),
    Field::string('role')->relation('members', 'team_user.role'),
    Field::string('color')->relation('lines.product', 'meta->color'),
    Field::date('ordered')->relation('orders', 'created_at'),
    Field::integer('quantity')->relation('lines', 'quantity'),
];
```

The path is a dot-separated chain of relation methods. Columns belong to the final related model. Omit the second argument to use the field's internal name: `Field::string('buyer', 'name')->relation('customer')` searches the customer's name.

Unqualified columns use the related model's table. Dotted columns are table qualifiers, such as a pivot column in `belongsToMany`; they do not add relation segments. Paths and columns come from application configuration.

The package supports belongs-to, has-one, has-many, many-to-many, through, and polymorphic relations. Paths may combine them, with at most one `morphTo` segment.

## Match related rows

Positive clauses use existence subqueries with the same column, date, and JSON predicates as root fields. Several target columns mean any column of one related row may match.

| Query | Match |
| --- | --- |
| `tag:php,laravel` | A related tag matches either value. |
| `tag:php tag:laravel` | Both tags exist, possibly on different rows. |
| `tag:!=php` | No related tag matches php. |
| `quantity:10..20` | One related row satisfies both bounds. |
| `quantity:>=10 quantity:<=20` | Separate related rows may satisfy the clauses. |
| `person:*` | A related row has at least one present target. |
| `-person:*` | No related row has a present target. |

Negation uses `whereDoesntHave()` with the positive predicate. It includes records without related rows and those whose related targets are NULL, regardless of `nullable()`.

Lists stay in one subquery unless they contain wildcard patterns, which the core expands into separate clauses. Repeated clauses each have their own subquery. Use a single range when both bounds must apply to one related value.

## Specify polymorphic types

Every path containing `morphTo` needs an explicit list of model classes:

```php
use App\Models\Post;
use App\Models\Video;
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('owner')->relation(
    'comments.commentable.owner',
    'name',
    [Post::class, Video::class],
);
```

The package uses `whereHasMorph()` or `whereDoesntHaveMorph()` at that segment. Pass model classes rather than morph-map aliases; Laravel's morph map still applies. Each class must support the remaining path and target columns.

A negative clause includes NULL morph references and records of types outside the list. Types are never discovered from stored data.

Invalid relation methods, missing types, unnecessary types, non-model classes, and multiple `morphTo` segments throw `LogicException`, including for empty search input.

## Reuse field behavior

Relations retain aliases, defaults, typing, storage conversions, text modes, and clause restrictions. Dates use the final model's date format and the search's time zone. Use `withoutTime()` for DATE columns.

Scalar JSON paths and validated dynamic keys work on related models. Relation fields cannot use JSON arrays, expressions, or custom resolvers. `column()` and `columns()` can replace the related targets after `relation()`.

Relations can be free-text targets, except for date fields and dynamic patterns. They follow the same candidate casting and wildcard rules as root fields.

## Include soft-deleted related models

Related global scopes apply inside subqueries. Call `withTrashedRelated()` to include deleted related rows:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('customer')->relation('orders.customer', 'name')->withTrashedRelated();
```

This includes deleted models at every segment using `SoftDeletes`, including intermediate parents in through relations. Other scopes remain active, and root soft-delete handling is unchanged.

Laravel applies only the intermediate model's soft-delete scope in through relations, not its other global scopes. Use a nested path, such as `lines.shipments`, when those scopes must apply.

## Index relation queries

Searches add existence subqueries, leaving the root query's joins unchanged. Eloquent may add pivot or intermediate joins inside those subqueries. Repeated clauses, nested paths, and polymorphic types each add query work.

Index the relation keys used for correlation. Pivot tables usually need an index starting with the owning key and a composite index covering both keys. Morph relations need type and ID indexes. Consider an index that also includes the searched column, and inspect query plans with representative data.

Reads of the root table inside relation and aggregate subqueries use derived tables. This permits MySQL updates and deletes through self relations, nested paths back to the root, and through relations with the root as an intermediate model.
