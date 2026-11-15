---
title: "Free-Text Terms"
description: "Search several text and typed fields at once with values that have no field name."
weight: 5
---

A free-text term is a value without a field name, such as `coffee` in `coffee status:open`. The core calls these bare terms. Each one searches the fields you list in `terms()`.

## Choose term targets

Pass public field names to `terms()`:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$search = Search::make()->fields([
    Field::string('name'),
    Field::string('description')->nullable(),
    Field::integer('quantity')->nullable(),
    Field::numeric('rating')->nullable(),
    Field::uuid('identifier', 'uuid')->nullable(),
])->terms(['name', 'description', 'quantity', 'rating', 'identifier']);
```

Every name must exist in `fields()`, even for denied fields and searches without free-text terms. Unknown names, dates, dynamic field patterns, and directives throw `InvalidArgumentException`. Denied fields are skipped.

## Match text and typed values

A term matches when any eligible target matches. Ordinary text fields use contains matching, even if their fielded mode is exact or prefix. Quoted terms search a contiguous phrase. [Full-text fields](full-text) search words or quoted phrases instead.

Separate terms use AND. `red coffee` requires both terms somewhere in the configured fields; they may match different targets.

Other field types participate only when the core's `tryCast()` accepts the candidate. `10` can match quantity 10; `coffee` skips integer, numeric, and UUID targets. Storage conversions run after casting, so scaled decimal values work as terms too.

Rejected candidates never reach SQL. Valid numeric candidates bind with the required SQL type, including values outside an integer column's range or fractional scaled values. Numeric term fields must target numeric storage on PostgreSQL.

## Use wildcards

A free-text wildcard compiles to a literal `TextPattern` on ordinary text fields; the SQL resolver renders it with `toLike()`. `Ana*` matches a whole value starting with Ana. It does not match Ana in the middle of a value. Full-text targets use a prefix on the last extracted word.

Each target must accept the pattern under its own wildcard and minimum-length rules. Fields with `withoutWildcards()`, `map()`, or `scale()` are skipped. Leading wildcard terms are disabled; use a fielded pattern with `allowLeadingWildcards()` when needed.

Quoted `"Ana*"` searches for a literal asterisk on ordinary text targets. Full-text targets treat the asterisk as punctuation and search the word Ana without a prefix operator.

## Search related or JSON values

[Relation fields](relations) search eligible related rows through existence subqueries. [JSON array fields](json-columns) match any correctly typed element. Both keep their usual typed comparisons and complement rules.

## Negate a term

A negative term requires every eligible field to miss. Nullable fields contribute NULL-aware complements, and relation targets include records without matching related rows.

If no target accepts a term, it matches no rows. Its negation matches every row. This also applies when no term targets are configured.
