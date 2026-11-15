---
title: "Operators and Values"
description: "Compare values, match lists and ranges, check existence, and combine clauses with boolean operators."
weight: 3
---

The package uses the query language of the core package. This page shows how each clause becomes SQL. [Query syntax](https://hosmelq.com/docs/search-syntax-parser/basic-usage/query-syntax) in the core documentation covers the full grammar.

## Compare values

A colon means equality. Add an operator after it for another comparison:

```text
status:open
price:>10
quantity:<=20
status:!=closed
```

The supported operators are `=`, `!=`, `>`, `>=`, `<`, and `<=`. Each field validates and casts its value before SQL is built.

## Match a list

Separate values with commas to match any of them:

```text
status:open,pending
quantity:0,10,20
status:!=closed,pending
```

Exact scalar lists use SQL IN. A negative list matches none of its values. Repeated clauses are combined with AND, so `price:>10 price:<20` requires both conditions.

## Match a range

Ranges include both endpoints. Use brackets with uppercase `TO`, or separate the endpoints with `..`:

```text
price:[10 TO 20]
price:10..20
price:*..20
price:10..*
```

An unquoted `*` leaves that end open. Two finite scalar bounds use BETWEEN; one finite bound uses `>=` or `<=`. `field:*..*` matches present values. Quoted `"*"` is literal text.

[Dates](dates-and-time-zones) include the full written precision of the upper endpoint. A range ending on January 15 includes that whole day.

## Check whether a value exists

`field:*` matches any non-NULL value, including an empty string or zero. `-field:*` matches NULL. With several columns, existence means at least one is present; absence means all are NULL.

[JSON paths](json-columns) exclude missing keys and JSON null from presence checks. JSON array fields are present when they contain a non-empty array, regardless of their elements' types.

## Combine clauses

Use AND, OR, parentheses, NOT, or a leading minus:

```text
(status:open OR status:pending) price:>10
NOT (status:closed OR quantity:>20)
```

Whitespace implies AND. AND binds more tightly than OR, and boolean keywords must be uppercase. See [complements and nullable columns](complements-and-nullable-columns) for negative filters.

## Restrict accepted clauses

Configure only the operators and clause shapes a field should accept:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::integer('quantity')
    ->operators('=', '!=', '>', '>=', '<', '<=')
    ->withoutWildcards();
```

`withoutLists()`, `withoutRanges()`, and `withoutExists()` reject those shapes. Rejected input produces a translated Laravel validation error.
