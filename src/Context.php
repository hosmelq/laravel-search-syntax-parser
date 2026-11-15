<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

final readonly class Context
{
    public function __construct(
        public Field $field,
        public string $driver,
        public DateTimeZone $timezone,
        public DateTimeImmutable $now,
    ) {}

    /**
     * The inner query omits global scopes so only the supplied predicate is complemented.
     *
     * @param Builder<*> $query
     * @param Closure(Builder<*>): void $positive
     */
    public static function complement(Builder $query, Closure $positive): void
    {
        [$key, $keys] = self::keys($query, $positive);

        $query->whereNotIn($key, $keys);
    }

    /**
     * @param Builder<*> $query
     * @param Closure(Builder<*>): void $positive
     *
     * @internal
     */
    public static function matching(
        Builder $query,
        Closure $positive,
        string $boolean = 'and',
    ): void {
        [$key, $keys] = self::keys($query, $positive);

        $query->whereIn($key, $keys, $boolean);
    }

    /**
     * @param Builder<*> $query
     * @param Closure(Builder<*>): void $positive
     *
     * @return array{string, Closure(QueryBuilder): void}
     */
    private static function keys(Builder $query, Closure $positive): array
    {
        $model = $query->getModel();
        $name = $model->getKeyName();
        $key = self::qualifiedKey($model, $name);
        $source = $query->getQuery()->from;

        // Eloquent changes a self-related model's table to its query alias. The independent
        // key query must read the original table while the outer key retains that alias.
        if (is_string($source) && ($alias = mb_stripos($source, ' as ')) !== false) {
            $model = clone $model;

            $model->setTable(mb_substr($source, 0, $alias));
        }

        // Registering and then removing global scopes keeps their builder macros, such as onlyTrashed().
        $matching = $model->newQuery()->withoutGlobalScopes();

        $positive($matching);

        $matching->select(self::qualifiedKey($model, $name));

        return [
            $key,
            static function (QueryBuilder $keys) use ($matching, $name): void {
                // A derived table lets MySQL update or delete the rows a search selects (error 1093).
                $keys->select('search_matches.'.$name)->fromSub($matching, 'search_matches');
            },
        ];
    }

    private static function qualifiedKey(Model $model, mixed $key): string
    {
        if (! is_string($key) || $key === '') {
            throw new LogicException('Strict complements require a single primary key.');
        }

        return $model->qualifyColumn($key);
    }
}
