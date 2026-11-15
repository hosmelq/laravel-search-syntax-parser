---
title: "Installation"
description: "Install Laravel Search Syntax Parser, publish its configuration, and prepare the database features your searches use."
weight: 2
---

Install the package with Composer and run its install command. Composer also installs [Search Syntax Parser](https://hosmelq.com/docs/search-syntax-parser), the core package that parses the queries.

## Requirements

Laravel Search Syntax Parser requires PHP 8.4 or later and Laravel 13. It supports SQLite, MySQL, MariaDB, and PostgreSQL.

## Install the package

Install the package with Composer:

```bash
composer require hosmelq/laravel-search-syntax-parser
```

Run the install command to publish `config/search-syntax-parser.php`:

```bash
php artisan search-syntax-parser:install
```

Laravel discovers the service provider automatically. See [configuration](configuration) for the available options.

## Publish files manually

You can also publish the configuration without the install command:

```bash
php artisan vendor:publish --tag=search-syntax-parser-config
```

To customize validation messages, publish the translations:

```bash
php artisan vendor:publish --tag=search-syntax-parser-translations
```

See [validation errors](basic-usage/errors) for adding another locale.

## Prepare the database

Ordinary column filters need no additional setup. The features below have database requirements:

| Feature | Requirement |
| --- | --- |
| [JSON columns](basic-usage/json-columns) | SQLite JSON functions. MySQL arrays use `JSON_TABLE()`. MariaDB arrays and dynamic keys use `JSON_TABLE()`, which requires MariaDB 10.6 or later. PostgreSQL supports `json` and `jsonb`. |
| [Full-text search](basic-usage/full-text) | A separate FULLTEXT index per target column on MySQL or MariaDB, or a matching GIN expression index on PostgreSQL. SQLite requires `likeFallback()`. |
| [Dates](basic-usage/dates-and-time-zones) | Stored datetimes and the database session time zone must agree with the application's storage zone. |

## Install the Boost skill

The package ships a [Laravel Boost](https://laravel.com/docs/13.x/boost) skill that shows AI agents how to define, apply, and test searches. If your application uses Boost, run its installer and select `hosmelq/laravel-search-syntax-parser` among the third-party packages:

```bash
php artisan boost:install
```

Run `php artisan boost:update` after updating the package to refresh the skill.

## Next steps

Review the [configuration](configuration), then [define a search](basic-usage/defining-a-search).
