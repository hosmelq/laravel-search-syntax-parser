---
title: "JSON Columns"
description: "Search typed values in JSON paths, arrays of scalars, and dynamic object keys."
weight: 8
---

Fields can target a value inside a JSON column, an array of scalars, or an object key that the user names in the query. Each comparison checks the JSON type as well as the value.

## Search a JSON path

Use Laravel's arrow syntax to select a JSON value:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::string('color')->column('meta->color'),
    Field::integer('width')->column('meta->size->width'),
    Field::decimal('price', 0, 2)->column('meta->price_cents')->scale(100),
    Field::boolean('featured')->column('meta->featured'),
    Field::in('state', ['open', 'closed'])->column('meta->state'),
    Field::string('label')->columns(['name', 'meta->label'])->nullable(),
];
```

Paths support comparisons, lists, ranges, text matching, wildcards, and free-text terms. They also work on [relation fields](relations), using the final related model's columns.

## Match the stored type

The field and its storage conversions determine the JSON type:

| Field or stored value | JSON type |
| --- | --- |
| `string()`, UUID, ULID, string members, and string-backed enums | String. |
| Integer, numeric, decimal, and integer-backed enums | Number. |
| Boolean | Boolean. |
| A value converted by `map()` or `scale()` | The converted storage type. |

A JSON string `"10"` does not match an integer field, and a JSON number `10` does not match a string field. Objects, arrays, and JSON null do not match scalar comparisons. Decimal query text compares numerically unless storage conversion maps it to text.

String fields ignore case. MySQL and MariaDB JSON comparisons fold text explicitly and distinguish accents. UUID, ULID, `in()`, and `enum()` values compare directly after core normalization. Wildcards use the same `ESCAPE '!'` handling as ordinary columns.

## Check presence and negate matches

A scalar path is present when the key exists and is not JSON null. `color:*` includes empty strings, zero, false, objects, and arrays. `-color:*` includes absent keys, JSON null, and NULL columns. An open range such as `color:*..*` uses the same presence rule.

Negative comparisons include missing paths, JSON null, and values of another type. JSON targets have this behavior without `nullable()`. Ordinary columns mixed into `columns()` still need `nullable()` when they can contain NULL.

## Search arrays of scalars

Use `jsonArray()` with a scalar factory to describe each element:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::string('tag')->jsonArray('tags'),
    Field::integer('size')->jsonArray('meta->sizes'),
];
```

| Query | Match |
| --- | --- |
| `tag:php` | Any string element equals php, ignoring case. |
| `tag:php,laravel` | Any element equals either value. |
| `tag:ph*` | Any string element starts with ph. |
| `size:10..20` | One numeric element satisfies both bounds. |
| `tag:php tag:laravel` | Both clauses match, possibly on different elements. |
| `tag:!=php` | No string element equals php. |

Empty arrays, NULL columns, missing paths, and non-array values match no positive element comparison. Their complements match. Nested arrays, objects, and JSON null elements do not match scalar comparisons.

For arrays, `tag:*` and `tag:*..*` mean a non-empty array, even if its elements have the wrong type. Their negations include empty arrays and non-array values. Array fields can be term targets.

`jsonArray()` replaces previous column target configuration. A later `column()`, `columns()`, or `expression()` replaces it in turn. Core `Field::array()` describes query input arrays and requires a custom resolver. Arrays of objects are outside this API, and relation fields cannot use `jsonArray()`.

## Accept dynamic keys

A field ending in `.*` requires a `keys()` rule and exactly one `*` segment in each JSON target:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::string('meta.*')->keys('/^[a-z0-9_.-]+$/')->column('attributes->*'),
    Field::string('tag.*')->keys('/^[a-z]+$/')->jsonArray('attributes->tags->*'),
];
```

`meta.color:red` searches `attributes->color`. A key such as `a.b` remains one object key. Exact definitions take precedence over patterns, and the longest matching pattern wins.

After the application's key rule, the package rejects keys containing `->`, double quotes, backslashes, square brackets, or control characters. These rejections produce positioned validation errors. Accepted keys identify single object members, and values remain bound parameters.

Dynamic fields cannot be directives or free-text targets, because a free-text term supplies no key.

## Prepare and index JSON storage

SQLite needs `json_extract()`, `json_type()`, and `json_each()`. Its built-in case folding covers ASCII. MySQL arrays use `JSON_TABLE()`. MariaDB arrays and dynamic keys use `JSON_TABLE()`, which requires MariaDB 10.6 or later. On MariaDB, an object that repeats a key reads as missing it for dynamic keys. PostgreSQL supports both `json` and `jsonb`, with guarded casts so values of the wrong type do not cause conversion errors.

The package creates no indexes. Array predicates inspect elements for each candidate row. For frequently searched values, create and index a generated column of the appropriate SQL type, then target that column.

Date fields reject JSON paths and arrays. Use a generated date or datetime column and configure a [date field](dates-and-time-zones).
