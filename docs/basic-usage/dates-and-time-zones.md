---
title: "Dates and Time Zones"
description: "Search datetime and DATE columns with calendar dates, relative values, and the user's time zone."
weight: 7
---

Date fields turn each written date into an interval in the user's time zone, then compare stored values with that interval.

## Define a date field

Use `Field::date()` for datetime columns. Add `withoutTime()` for SQL DATE columns:

```php
use App\Models\Product;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;

$search = Search::make()
    ->fields([
        Field::date('created')->column('created_at')->allowPartialDates()->nullable(),
        Field::date('due')->column('due_on')->withoutTime()->nullable(),
    ])
    ->timezone($user->timezone)
    ->query($request->input('q'));

$products = Product::query()->tap($search)->get();
```

Date fields support columns, several columns, expressions, relations, and date aggregates. They cannot be free-text targets or JSON targets. Use a generated date column for dates stored in JSON.

The second argument to `date()` is an input format, not a column name. A custom format requires a [custom resolver](../advanced-usage/custom-resolvers).

## Write dates and relative values

Full dates use `YYYY-MM-DD`. Datetimes use ISO notation, such as `2025-01-15T10:30:00.5`. `allowPartialDates()` also accepts a year or month:

```text
created:2025-01-15
created:2025-01
created:2025-01-15T10:30+02:00
created:>=today
created:[yesterday TO tomorrow]
created:>now-7d
```

A written `Z` or numeric offset takes precedence over the search's time zone. Values without an offset use that zone.

| Value | Meaning |
| --- | --- |
| `today`, `yesterday`, `tomorrow` | The whole local day. |
| `now` | The current instant, spanning one microsecond. |
| `today-7d` | The whole local day seven days ago. |
| `now-7d`, `-7d` | The instant seven calendar days ago. |

Each anchor accepts one offset: `y` for years, `M` for months, `w` for weeks, or `d` for days. `now` also accepts `h` for hours and `m` for minutes. Units are case-sensitive; anchors are not.

Calendar offsets keep local wall-clock time. Month and year offsets clamp to the target month's last day. Hours and minutes measure elapsed time, so `now-7d` and `now-168h` can differ across a daylight-saving transition.

The search reads Laravel's `Date::now()` once per invocation. Clauses, lists, ranges, and defaults share that value. Use `Date::setTestNow()` to control it in application tests.

## Set the user's time zone

`timezone()` accepts a zone name, a `DateTimeZone`, or a closure without arguments:

```php
$search->timezone(fn (): ?string => auth()->user()?->timezone);
```

The closure runs once per invocation and may return a zone name, a `DateTimeZone`, or `null`. `null` and an omitted `timezone()` use `config('app.timezone')`.

An invalid zone string throws PHP's `DateInvalidTimeZoneException`. Strings passed directly are checked immediately; strings returned by a closure are checked when the search runs.

## Store datetimes consistently

Stored datetimes use `config('app.timezone')` and the model's `getDateFormat()`. Relation and aggregate fields use the related model's date format. The resolver converts query bounds to the storage zone before binding them.

With UTC storage and the user zone `America/New_York`, `created:2025-01-15` starts at `2025-01-15 05:00:00` and ends just before `2025-01-16 05:00:00`.

MySQL TIMESTAMP and PostgreSQL `timestamp with time zone` columns interpret bound timestamps through the database session time zone. Keep that zone consistent with the application's storage zone. MySQL DATETIME and PostgreSQL `timestamp` do not perform this conversion.

Local days may last 23 or 25 hours. A skipped midnight starts at the first instant after the gap; a repeated time starts at its first occurrence. A skipped local time has an empty interval.

## Search calendar dates

`withoutTime()` defines the core calendar-date contract for query and stored values. It rejects datetimes, `now`, and bare offsets. Full dates and day anchors remain valid, as do partial dates when enabled.

Calendar bounds bind as `Y-m-d` in the user's zone, without storage-zone conversion. This also supports SQLite values written as `Y-m-d H:i:s` by an Eloquent date cast.

## Compare intervals

A date value has an inclusive start S and an exclusive end E:

| Query | Condition |
| --- | --- |
| `created:d` | `target >= S AND target < E` |
| `created:>d` | `target >= E` |
| `created:>=d` | `target >= S` |
| `created:<d` | `target < S` |
| `created:<=d` | `target < E` |
| `created:[a TO b]`, `created:a..b` | `target >= S(a) AND target < E(b)` |

An unquoted `*` opens a range end. Lists match any interval. Negative lists require every interval to miss. Existence checks use IS NOT NULL and IS NULL.

NOT, a leading minus, and `!=` complement the full interval. Add `nullable()` to include NULL. With several targets, a positive clause matches any target; its complement requires all targets to miss.

## Match storage precision

Bounds round up to the model format's precision: microseconds for `u`, milliseconds for `v`, otherwise seconds. On a seconds column, `created:2025-01-15T10:30:00.5` matches nothing; `created:>2025-01-15T10:30:00.5` starts at `10:30:01`.

An empty interval's complement matches every present value, plus NULL for nullable fields. After conversion and rounding, bounds outside years 0001 through 9999 are either omitted or reduced to an empty match. They never reach SQL. Narrower date ranges imposed by an individual column are not inspected.
