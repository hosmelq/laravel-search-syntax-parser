---
title: "Fields and Columns"
description: "Map public field names to columns, several columns, or trusted SQL expressions."
weight: 2
---

A field gives a searchable value the public name users type, a type that validates their input, and the target the package queries. The target is a column unless you choose another.

## Define a field

Create fields with the `Field` factories:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::string('name', 'company_name'),
    Field::integer('quantity')->min(0)->nullable(),
    Field::decimal('price', 0, 2)->column('price_cents')->scale(100),
    Field::uuid('identifier', 'uuid')->nullable(),
];
```

`Field` extends the core `Validation\Field`. Its aliases, defaults, ignored values, operator restrictions, and storage conversions remain available. Other factories include `numeric()`, `boolean()`, `ulid()`, `in()`, `enum()`, and `date()`.

The field uses its internal name as the column unless you set another target. Unqualified columns are qualified against the current model's table.

## Search several columns

Call `columns()` to match any of several targets:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('person')
    ->columns(['first_name', 'last_name'])
    ->nullable();
```

The list must contain at least one column. A negative clause requires every target to miss. Use `nullable()` when a target can contain NULL; see [complements](complements-and-nullable-columns).

A dotted column such as `contacts.email` is a table qualifier. It does not traverse a relation or add a join. Add the join on your builder, or use [relation fields](relations).

## Use an expression

Pass a closure to `expression()`. It receives the connection driver and returns an SQL expression:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use Illuminate\Database\Query\Expression;

$field = Field::string('full_name')->expression(
    fn (string $driver): Expression => new Expression(
        $driver === 'sqlite'
            ? "first_name || ' ' || last_name"
            : "concat(first_name, ' ', last_name)",
    ),
)->nullable();
```

Expressions are application configuration. Never interpolate request input into them. The package adds parentheses around expressions before comparisons and NULL checks.

Database expression behavior still applies. SQLite concatenation and MySQL `concat()` propagate NULL, while PostgreSQL `concat()` ignores NULL arguments. Use CASE or COALESCE when the result must agree across drivers.

## Convert values for storage

`scale()` and `map()` run after parsing, before values are bound to SQL. They apply to comparisons, list items, range endpoints, and typed terms. Open range endpoints are left open.

For example, `price:10.50` binds `1050` when the field uses `scale(100)`. The parsed syntax tree keeps the user's original value.

Numeric fields must target numeric columns or expressions. PostgreSQL binds their values as `bigint` or `numeric`. If a numeric field's `map()` stores strings, all its values bind as text instead, so it must target text storage. Use `Field::string()` for numeric codes stored as text, such as ZIP codes.

## Choose another target

| Target | Use |
| --- | --- |
| [JSON path or array](json-columns) | Typed values inside a JSON column. |
| [Relation](relations) | Attributes of related models. |
| [Aggregate](aggregates) | Counts, sums, averages, minima, and maxima over related rows. |
| [Scope or predicate](scopes-and-predicates) | Values that select application predicates. |
| [Custom resolver](../advanced-usage/custom-resolvers) | SQL behavior supplied by your application. |

`column()`, `columns()`, `jsonArray()`, and `expression()` replace the previous column target. The last call wins. Relation fields accept column targets but reject JSON arrays, expressions, and custom resolvers. Aggregate and scope targets cannot be combined with another target type.

Core array fields and dates with custom input formats require a custom resolver. [Directive fields](directives-and-sorting) also require one, except for `Field::trashed()`.

Public field names must be unique. The core rejects duplicates with `InvalidArgumentException` when the search runs or is described, including duplicate pattern names.
