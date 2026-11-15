---
title: "Scopes and Predicates"
description: "Map allowed values and booleans to Eloquent scopes and closures, with complements that include NULL."
weight: 11
---

A scope field lets a public value, such as `is:overdue`, select an Eloquent scope or closure instead of comparing a column.

## Map values to predicates

Use `scopes()` when a public value selects an application predicate:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use Illuminate\Database\Eloquent\Builder;

$field = Field::in('is', ['overdue', 'ready', 'unassigned'])->scopes([
    'overdue' => 'overdue',
    'ready' => 'ready',
    'unassigned' => function (Builder $query): void {
        $query->whereNull($query->qualifyColumn('assignee_id'));
    },
]);
```

Strings name local scopes, including Laravel's `#[Scope]` and `scopeX` methods. Closures receive the query selecting matching primary keys. They may add OR conditions, join other tables, or read the same table.

Every value of an `in()` or backed `enum()` field needs exactly one predicate. Enum keys use backing values, while search input may still use case names. Missing values, extra keys, duplicate normalized keys, and incompatible field types are configuration errors. Storage conversions that change an allowed value are also rejected because they could select another predicate.

## Combine scope values

| Query | Match |
| --- | --- |
| `is:overdue` | The overdue predicate. |
| `is:overdue,unassigned` | Either predicate. |
| `is:!=overdue` | Every row outside the overdue matching set. |
| `NOT is:overdue,ready` | Neither predicate. |

Lists combine predicates with OR. Separate clauses follow the normal boolean language. Aliases, defaults, and value validation still apply.

Only equality, inequality, and lists are supported. Ordering, ranges, wildcards, and existence checks produce positioned validation errors. Scope targets cannot be combined with column, expression, JSON, relation, aggregate, or custom resolver targets.

## Define predicate booleans

Use `Field::presence()` for a nullable column, or give a boolean field one or two predicates:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use Illuminate\Database\Eloquent\Builder;

$fields = [
    Field::presence('archived', 'archived_at'),
    Field::boolean('late')->scopes([true => 'overdue']),
    Field::boolean('assigned')->scopes([
        false => function (Builder $query): void {
            $query->whereNull($query->qualifyColumn('assignee_id'));
        },
    ]),
];
```

`archived:true` compiles to a plain column existence check; `archived:false` checks absence. The core presence field is boolean and allows only equality, inequality, and lists. Both boolean values together match everything; the negated list matches nothing.

Boolean scope keys accept `true` and `false`, stored by PHP as 1 and 0, or the strings `'true'` and `'false'`. With one predicate, the other value is its strict complement. If overdue means `due_at < now()`, `late:false` includes NULL due dates.

With two predicates, each defines its own set. They may overlap or leave rows unmatched. `flag:false` applies the false predicate, while `flag:!=true` always complements the true predicate. An empty mapping is rejected.

## Complement the matching set

SQL NOT does not match a NULL result. Scope fields instead exclude the keys selected by the positive predicate:

```sql
where products.id not in (
    select search_matches.id from (
        select products.id from products where <positive predicate>
    ) as search_matches
)
```

The derived table permits MySQL updates and deletes when the predicate reads the table being changed. A single named primary key is required.

The inner query starts without global scopes so it complements the predicate alone. Root constraints and global scopes still limit the final result. [Custom resolvers](../advanced-usage/custom-resolvers#strict-complements) can use the same helper.

Key subqueries add work, especially for negative filters. Inspect query plans for expensive predicates and large candidate sets.
