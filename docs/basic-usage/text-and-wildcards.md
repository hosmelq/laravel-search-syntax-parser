---
title: "Text and Wildcards"
description: "Choose exact, prefix, or substring matching, use wildcard patterns, and index the searched text."
weight: 4
---

String fields match whole values, ignoring case. They can also match a prefix or a substring, or take wildcard patterns from the query.

## Choose a matching mode

String fields use exact, case-insensitive matching by default. Call `prefix()` or `contains()` to change unquoted equality values:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::string('sku')->prefix(),
    Field::string('name')->contains(),
];
```

| Query | Match |
| --- | --- |
| `sku:ABC` | Starts with `ABC`. |
| `name:mug` | Contains `mug`. |
| `name:"mug"` | Equals `mug`. |
| `name:mug,"tea cup"` | Contains `mug` or equals `tea cup`. |

Quotes force exact equality, including inside a list. Ordering and range operators keep their comparison behavior. Use [words()](full-text) to search words and phrases within stored text.

## Use wildcard patterns

An unquoted asterisk marks a wildcard:

```text
name:Ana*
name:A*Bell
name:!=Ana*
name:"Ana Bell"*
```

Patterns match the whole stored value. They are not wrapped in additional wildcards. Quoted asterisks stay literal, and `field:*` is an existence check.

Leading wildcards are disabled by default. Enable them per field and optionally require a minimum literal length:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('name')->allowLeadingWildcards()->minWildcardLength(3);
```

The core escapes literal `%`, `_`, and `!`. SQL LIKE predicates use `ESCAPE '!'`, and backslashes stay literal, including with MySQL's `NO_BACKSLASH_ESCAPES` mode. Search values are parameter bindings.

## Case and accents

| Database | String comparison |
| --- | --- |
| MySQL and MariaDB | Uses the column or expression collation. Choose a case-insensitive collation such as `utf8mb4_unicode_ci`. Accent matching follows that collation. |
| PostgreSQL | Compares `lower(cast(target as text))` with `lower(?)`. Case folding follows the database locale. |
| SQLite | Uses `COLLATE NOCASE` and case-insensitive LIKE. Built-in folding covers ASCII; keep `case_sensitive_like` disabled. |

Quoted values follow the same rules. MySQL and MariaDB [JSON strings](json-columns) are explicitly folded to lowercase because extracted text has a binary collation; they ignore case but distinguish accents.

Numeric comparisons do not fold text. UUID, ULID, `in()`, and `enum()` values are normalized by the core and compared directly, so a native PostgreSQL UUID column can use its ordinary index.

## Index the searched expression

Contains matching and ordinary free-text terms use `%term%` patterns, which generally cannot use a B-tree index for lookup. PostgreSQL's `pg_trgm` extension can index these searches with GIN or GiST.

PostgreSQL string predicates need an index on the same `lower(cast(... as text))` expression. SQLite comparison indexes need `COLLATE NOCASE`. Prefix LIKE index use depends on the database's collation and operator support.

On PostgreSQL, comparing fractional values with an integer column casts the column to `numeric`, so an ordinary B-tree index is not used. Give decimal fields a `scale()` when storage uses whole units. Check query plans with representative data.
