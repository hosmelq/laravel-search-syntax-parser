---
title: "Configuration"
description: "Configure the validation error key and the query limits, and override them for a single search."
weight: 3
---

The [install command](installation) publishes `config/search-syntax-parser.php`. You can leave the file unpublished to use its defaults.

| Option | Default | Description |
| --- | --- | --- |
| `error_key` | `'q'` | Attribute used for search validation errors. Form request rules use the attribute being validated. |
| `max_clauses` | `50` | Maximum number of user-supplied leaf clauses, including ignored clauses. |
| `max_depth` | `5` | Maximum nesting depth of groups and negations. |
| `max_length` | `1000` | Maximum number of Unicode characters in the raw input, including whitespace. |

The limits must be positive integers. Field defaults do not count toward the clause or depth limits. Invalid configuration throws an exception; input that exceeds a limit produces a [validation error](basic-usage/errors).

## Environment variables

The three query limits can be set through environment variables:

| Option | Environment variable |
| --- | --- |
| `max_clauses` | `SEARCH_SYNTAX_PARSER_MAX_CLAUSES` |
| `max_depth` | `SEARCH_SYNTAX_PARSER_MAX_DEPTH` |
| `max_length` | `SEARCH_SYNTAX_PARSER_MAX_LENGTH` |

`error_key` has no environment variable. Edit it in `config/search-syntax-parser.php`.

## Override one search

Call the corresponding method to change an option for one search:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$search = Search::make()
    ->errorKey('filters.q')
    ->maxClauses(30)
    ->maxDepth(4)
    ->maxLength(800);
```

You can also use these methods in the callback passed to `whereSearch()` or the [form request rule](advanced-usage/validation).

See the [core query limits](https://hosmelq.com/docs/search-syntax-parser/advanced-usage/query-limits-and-untrusted-input) for how clauses and depth are counted. Fields, term targets, sorting, and time zones belong to the [search definition](basic-usage/defining-a-search).
