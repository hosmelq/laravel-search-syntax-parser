---
title: "Validation Errors"
description: "Return translated validation errors with source positions, and fix configuration errors in the search definition."
weight: 15
---

Invalid user input becomes a Laravel validation error. Mistakes in the search definition stay exceptions.

## Handle invalid input

Invalid syntax, unknown fields, invalid values, and query limit failures become Laravel `ValidationException` messages. Laravel returns its usual validation response, including HTTP 422 for JSON requests.

```php
use App\Models\Product;
use Illuminate\Validation\ValidationException;

try {
    $products = Product::query()->whereSearch($request->input('q'))->get();
} catch (ValidationException $exception) {
    $messages = $exception->errors();
}
```

Both `whereSearch()` and `Search::query()` accept strings, `Stringable` values, and `null`. `null` is an empty query. The core rejects arrays and other values with `query.invalid_type`, which the adapter translates into a validation error. Invalid user filters are never silently dropped.

See [configuration](../configuration) for the error key, query limits, and per-search overrides. The [form request rule](../advanced-usage/validation) attaches errors to the attribute being validated.

## Read source positions

Messages include the original source position when available. Positions are zero-based Unicode code-point offsets, including whitespace in the original input.

The package supplies English messages for the core parser's error reasons and interpolates the exception parameters. Denied fields are excluded from allowed-field suggestions.

## Translate messages

Publish the translations:

```bash
php artisan vendor:publish --tag=search-syntax-parser-translations
```

The package's English messages are in `resources/lang/en/errors.php`. Publishing copies them to `lang/vendor/search-syntax-parser/en/errors.php`. Add another locale at `lang/vendor/search-syntax-parser/{locale}/errors.php`, using the same nested arrays. For Spanish, create `lang/vendor/search-syntax-parser/es/errors.php`:

```php
return [
    'field' => [
        'invalid_value' => 'El valor del campo ":field" no es válido.',
    ],

    'position' => 'En la posición :position.',

    'query' => [
        'invalid_type' => 'La búsqueda debe ser un texto.',
    ],
];
```

The groups follow the core's dotted error codes: `directive`, `field`, `query`, `syntax`, and `wildcard`. For example, `field.invalid_value` is read as `search-syntax-parser::errors.field.invalid_value`.

The package provides `field.not_allowed_none` for an empty core `allowed` list and translates the core's `query.invalid_type` for invalid request input. `field.operator_not_allowed` uses the core's `operation` parameter for either an operator or a clause form. `position` remains a top-level key that formats the source-position suffix. Preserve message placeholders such as `:field` and `:position`. Laravel's current and fallback locales apply.

## Fix configuration errors

Application configuration errors remain exceptions. Examples include an unknown term target, invalid defaults, unsupported target combinations, invalid relation paths, and a full-text field on SQLite without `likeFallback()`.

Term configuration errors use `InvalidArgumentException` with the core's message. Configuration checks still cover denied fields, including their defaults. These errors should be fixed in the search definition. They are distinct from invalid user values, which produce validation messages before execution. Exceptions from application resolver code also propagate, except for `SearchSyntaxException`, which is translated into a validation error.

The core supplies `getMessageKey()` and `getMessageParameters()` for translations. The adapter uses that catalog key and its string replacements, then appends the translated source-position suffix.
