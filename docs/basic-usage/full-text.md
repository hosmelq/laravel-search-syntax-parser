---
title: "Full-Text Search"
description: "Match whole words, phrases, and word prefixes with database full-text search, or a LIKE fallback on SQLite."
weight: 12
---

Fields with `words()` match whole words and phrases through the database's full-text search, instead of substring patterns.

## Search words and phrases

Call `words()` on a string field:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$search = Search::make()->fields([
    Field::string('content', 'description')->words()->nullable(),
])->terms(['content']);
```

Unquoted values require every word in any order. Quotes search a phrase within the stored text. Lists match any member; negative clauses complement the whole match, including NULL for nullable fields.

| Query | Match |
| --- | --- |
| `content:coffee` | The word coffee anywhere in the column. |
| `content:coffee-ceramic` | Both words, in either order. |
| `content:"coffee ceramic"` | Consecutive words in this order. |
| `content:coff*` | A word starting with coff. |
| `content:"fresh coff"*` | Fresh and a word starting with coff, in either order. |
| `content:coffee,espresso` | Either word. |
| `content:!=coffee,espresso` | Neither word, including NULL. |

Only a trailing wildcard is accepted. Leading and infix patterns produce validation errors, and `allowLeadingWildcards()` cannot be enabled on these fields. Quoted asterisks are punctuation. Ordering, ranges, and existence checks retain ordinary column behavior.

The core extracts Unicode letters, numbers, and combining marks, treating other characters as separators. The package builds database full-text queries from those words and their phrase or prefix metadata. User punctuation cannot add database full-text operators. Input without words matches nothing; its complement matches everything.

## Choose targets

Each `columns()` target gets a separate predicate. Every required word must match the same column, and each column needs its own index. [Relation columns](relations) and free-text terms also support word matching.

The built-in resolver requires plain columns. JSON paths, JSON arrays, and expressions require a custom resolver for full-text behavior. MySQL and MariaDB relation predicates use a derived query of matching primary keys, so those models need one named primary key.

## Index MySQL and MariaDB columns

Create a FULLTEXT index for each target column:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('products', function (Blueprint $table): void {
    $table->fullText('description');
    $table->fullText('name');
});
```

The resolver uses `MATCH (column) AGAINST (? IN BOOLEAN MODE)`. A combined index across two columns does not replace these separate indexes. Matching and accents follow the column collation and database parser.

Tokenization, stopwords, and minimum token lengths still apply. InnoDB normally ignores tokens shorter than three characters; MyISAM normally uses four. Prefix searches have different token-length and stopword behavior, and MariaDB has its own engine settings. Rebuild affected indexes after changing these settings.

InnoDB full-text indexes find committed rows. Application tests that use these indexes must commit their fixtures before querying them.

## Index PostgreSQL columns

PostgreSQL uses `to_tsvector(config, column) @@ to_tsquery(config, ?)`. The default configuration is `simple`. Select another configuration per field:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('content', 'description')->words()->fullTextLanguage('english');
```

The configuration is trusted application code and is quoted as a SQL literal. Query words are bound separately. PostgreSQL applies its dictionaries, stemming, and stopwords, including to phrases.

Create a GIN expression index with the same column and configuration:

```sql
CREATE INDEX products_description_fulltext ON products
USING GIN (to_tsvector('english'::regconfig, description));
```

Each target needs its own expression index. A generated search-vector column requires a custom resolver that targets it.

## Enable the SQLite approximation

SQLite requires an explicit fallback, including for searches with empty input:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('content', 'description')->words()->nullable()->likeFallback();
```

Without it, a configured word field throws `LogicException`. The package does not use SQLite full-text extensions.

The fallback requires `LIKE '%word%' ESCAPE '!'` for every word. It matches substrings, ignores phrase order, and treats prefixes as substring searches. For example, coffee also matches coffeemaker. Lists, alternative targets, and nullable complements still apply, but ordinary B-tree indexes cannot serve these substring lookups.

`likeFallback()` affects only SQLite. Other supported databases keep using full-text search.
