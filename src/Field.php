<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel;

use Closure;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Laravel\Resolvers\ColumnResolver;
use HosmelQ\SearchSyntaxParser\Laravel\Resolvers\RelationResolver;
use HosmelQ\SearchSyntaxParser\Laravel\Resolvers\ScopeResolver;
use HosmelQ\SearchSyntaxParser\Laravel\Resolvers\TrashedResolver;
use HosmelQ\SearchSyntaxParser\Laravel\Support\Aggregate;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Validation\Field as CoreField;
use HosmelQ\SearchSyntaxParser\Validation\TextMatch;
use HosmelQ\SearchSyntaxParser\Validation\Validators\DateValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;
use Override;

class Field extends CoreField
{
    private null|Aggregate $aggregate = null;

    /**
     * @var null|non-empty-list<string>
     */
    private null|array $columns = null;

    private null|float|int|string $emptyValue = null;

    /**
     * @var null|Closure(string): Expression
     */
    private null|Closure $expression = null;

    private string $fullTextLanguage = 'simple';

    private bool $jsonArray = false;

    private bool $likeFallback = false;

    private null|string $relation = null;

    /**
     * @var list<class-string<Model>>
     */
    private array $relationTypes = [];

    /**
     * @var null|Closure(Builder<*>, Clause, Context): void|Resolver
     */
    private null|Closure|Resolver $resolver = null;

    /**
     * @var null|array<string, Closure(Builder<*>): void|string>
     */
    private null|array $scopes = null;

    private bool $trashedDirective = false;

    private bool $trashedRelated = false;

    public static function trashed(): static
    {
        $field = static::in('trashed', ['with', 'only', 'without'])
            ->directive()
            ->default('without');
        $field->trashedDirective = true;

        return $field;
    }

    #[Override]
    public function accepts(string $field): bool
    {
        if (! parent::accepts($field)) {
            return false;
        }

        $key = $this->keyOf($field);

        if ($key === null) {
            return true;
        }

        return ! str_contains($key, '->') && ! str($key)->test('/["\\\\\[\]\\p{Cc}]/u');
    }

    /**
     * @param Builder<*> $query
     */
    public function assertValidAggregate(Builder $query): void
    {
        $this->aggregate?->relation($query->getModel());

        if (! $this->isDate() || ! is_string($this->emptyValue)) {
            return;
        }

        $format = $this->isCalendarDate() ? 'Y-m-d' : $this->dateFormat($query);

        if (! new DateValidator($format)($this->emptyValue, $this->getName())) {
            throw new LogicException(
                'whenEmpty() on date field "'.$this->getName().'" requires a date in the format '
                    .$format.'.',
            );
        }
    }

    #[Override]
    public function assertValidConfiguration(): void
    {
        parent::assertValidConfiguration();

        if ($this->isDirective() && ! $this->trashedDirective && $this->resolver === null) {
            throw new LogicException('Directive field "'.$this->getName().'" requires using().');
        }

        // A scope is chosen by value, so a conversion that changes values could pick another scope.
        if ($this->scopes !== null) {
            /** @var list<bool|float|int|string> $values */
            $values = $this->getAllowedValues();

            if (array_any(
                $values,
                fn (bool|float|int|string $value): bool => $this->toStorage($value) !== $value,
            )) {
                throw new LogicException('scopes() cannot be combined with map() or scale().');
            }
        }

        $columns = $this->expression instanceof Closure
            ? []
            : ($this->columns ?? [$this->getInternalName()]);

        if ($this->isPattern() && ($this->expression instanceof Closure || ! array_all(
            $columns,
            static fn (string $column): bool => str_contains($column, '->')
                && count(
                    array_filter(
                        explode('->', $column),
                        static fn (string $part): bool => $part === '*',
                    ),
                ) === 1
                && explode('->', $column)[0] !== '*',
        ))) {
            throw new LogicException(
                'Dynamic field "'.$this->getName().'" requires a JSON path with one * segment.',
            );
        }

        if ($this->isDate() && ($this->jsonArray || array_any(
            $columns,
            static fn (string $column): bool => str_contains($column, '->'),
        ))) {
            throw new LogicException(
                'Date field "'.$this->getName().'" requires a date column, not a JSON target.',
            );
        }

        if ($this->resolver !== null) {
            return;
        }

        if ($this->isDate() && $this->hasCustomDateFormat()) {
            throw new LogicException(
                'Date field "'
                    .$this->getName()
                    .'" with a custom format requires a custom resolver.',
            );
        }

        if ($this->expectsArray()) {
            throw new LogicException('Array fields require a custom resolver.');
        }

        if ($this->getTextMatch() === TextMatch::Words && (
            $this->jsonArray || $this->expression instanceof Closure || array_any(
                $columns,
                static fn (string $column): bool => str_contains($column, '->'),
            )
        )) {
            throw new LogicException(
                'words() requires plain column targets with full-text indexes.',
            );
        }
    }

    /**
     * @param Builder<*> $query
     */
    public function assertValidQuery(Builder $query): void
    {
        if ($this->trashedDirective && ! $query->getModel()::isSoftDeletable()) {
            throw new LogicException('trashed() requires a model that uses SoftDeletes.');
        }

        if (
            $this->getTextMatch() === TextMatch::Words
            && $this->resolver === null
            && $query->getModel()->getConnection()->getDriverName() === 'sqlite'
            && ! $this->likeFallback
        ) {
            throw new LogicException(
                'words() requires full-text search on SQLite; enable likeFallback().',
            );
        }
    }

    public function assertValidScopes(Model $model): void
    {
        foreach ($this->scopes ?? [] as $predicate) {
            if (is_string($predicate) && ! $model->hasNamedScope($predicate)) {
                throw new LogicException('Unknown local scope: '.$predicate);
            }
        }
    }

    /**
     * @param (Closure(): bool)|(Closure(null|Authenticatable): bool)|string $ability
     */
    #[Override]
    public function authorize(Closure|string $ability): static
    {
        return parent::authorize(static fn (): bool => $ability instanceof Closure
            ? $ability(Auth::user())
            : Gate::allows($ability));
    }

    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    public function avgOf(string $relation, string $column, null|Closure $constraint = null): static
    {
        return $this->aggregate('avg', $relation, $column, $constraint);
    }

    public function column(string $column): static
    {
        return $this->columns([$column]);
    }

    /**
     * @param list<string> $columns
     */
    public function columns(array $columns): static
    {
        $this->assertNoComputedTarget('columns()');

        if ($columns === []) {
            throw new InvalidArgumentException('columns() requires at least one column.');
        }

        $this->columns = $columns;
        $this->expression = null;
        $this->jsonArray = false;

        return $this;
    }

    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    public function countOf(string $relation, null|Closure $constraint = null): static
    {
        return $this->aggregate('count', $relation, '*', $constraint);
    }

    /**
     * @param Builder<*> $query
     */
    public function dateFormat(Builder $query): string
    {
        return ($this->aggregate?->relation($query->getModel())->getRelated() ?? $query->getModel())
            ->getDateFormat();
    }

    /**
     * @param Closure(string): Expression $expression
     */
    public function expression(Closure $expression): static
    {
        $this->assertNoRelation('expression()');

        $this->expression = $expression;
        $this->columns = null;
        $this->jsonArray = false;

        return $this;
    }

    public function fullTextLanguage(string $config): static
    {
        $this->fullTextLanguage = $config;

        return $this;
    }

    public function getFullTextLanguage(): string
    {
        return $this->fullTextLanguage;
    }

    public function getRelation(): null|string
    {
        return $this->relation;
    }

    /**
     * @return list<class-string<Model>>
     */
    public function getRelationTypes(): array
    {
        return $this->relationTypes;
    }

    /**
     * @return Closure(Builder<*>, Clause, Context): void|Resolver
     */
    public function getResolver(): Closure|Resolver
    {
        return $this->resolver
            ?? match (true) {
                $this->trashedDirective => new TrashedResolver(),
                $this->scopes !== null => new ScopeResolver(),
                $this->relation !== null => new RelationResolver(),
                default => new ColumnResolver(),
            };
    }

    public function includesTrashedRelated(): bool
    {
        return $this->trashedRelated;
    }

    public function isJsonArray(): bool
    {
        return $this->jsonArray;
    }

    #[Override]
    public function isNullable(): bool
    {
        return $this->aggregate instanceof Aggregate
            ? $this->aggregate->function !== 'count' && $this->emptyValue === null
            : parent::isNullable();
    }

    public function jsonArray(string $target): static
    {
        $this->assertNoRelation('jsonArray()');

        $this->column($target);

        $this->jsonArray = true;

        return $this;
    }

    public function likeFallback(): static
    {
        $this->likeFallback = true;

        return $this;
    }

    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    public function maxOf(string $relation, string $column, null|Closure $constraint = null): static
    {
        return $this->aggregate('max', $relation, $column, $constraint);
    }

    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    public function minOf(string $relation, string $column, null|Closure $constraint = null): static
    {
        return $this->aggregate('min', $relation, $column, $constraint);
    }

    #[Override]
    public function operators(string ...$operators): static
    {
        if ($this->scopes !== null && array_diff($operators, ['=', '!=']) !== []) {
            throw new LogicException('scopes() allows only the = and != operators.');
        }

        return parent::operators(...$operators);
    }

    /**
     * @param null|list<string>|string $columns
     * @param list<class-string<Model>> $types
     */
    public function relation(
        string $path,
        null|array|string $columns = null,
        array $types = [],
    ): static {
        $this->assertNoComputedTarget('relation()');

        if ($this->jsonArray || $this->expression instanceof Closure || $this->resolver !== null) {
            throw new LogicException(
                'relation() cannot be combined with jsonArray(), expression() or using().',
            );
        }

        if (! array_all(
            $types,
            static fn (string $type): bool => is_subclass_of($type, Model::class),
        )) {
            throw new LogicException('Relation types must be a list of Eloquent model classes.');
        }

        if ($columns !== null) {
            $this->columns(is_string($columns) ? [$columns] : $columns);
        } else {
            $this->columns = null;
        }

        $this->relation = $path;
        $this->relationTypes = $types;

        return $this;
    }

    /**
     * @return Closure(Builder<*>): void|string
     *
     * @internal
     */
    public function scopePredicate(bool|float|int|string $value): Closure|string
    {
        /** @var array<string, Closure(Builder<*>): void|string> $scopes */
        $scopes = $this->scopes;

        return $scopes[$this->scopeKey($value)];
    }

    /**
     * Boolean keys may be true/false (PHP stores them as 1/0) or their string forms.
     *
     * @param array<int|string, Closure(Builder<*>): void|string> $scopes
     */
    public function scopes(array $scopes): static
    {
        $this->assertNoColumnTarget('scopes()');

        if ($this->isPresence()) {
            throw new LogicException('scopes() cannot be combined with presence().');
        }

        $boolean = $this->getValueType() === 'boolean';
        $values = $this->getAllowedValues();

        if ($values === null || $values === []) {
            throw new LogicException('scopes() requires an in(), enum() or boolean() field.');
        }

        $predicates = [];

        foreach ($scopes as $key => $predicate) {
            $predicate = $this->scopeCallback($predicate);
            $value = $this->cast((string) $key, false);

            if (
                ! in_array($value, $values, true)
                || array_key_exists($this->scopeKey($value), $predicates)
            ) {
                throw new LogicException(
                    'Scope keys must uniquely match the allowed field values.',
                );
            }

            $predicates[$this->scopeKey($value)] = $predicate;
        }

        if ($boolean && count($predicates) === 1) {
            $missing = ! array_key_exists($this->scopeKey(true), $predicates);
            $predicate = array_values($predicates)[0];
            $predicates[$this->scopeKey($missing)] = static function (Builder $query) use (
                $predicate,
            ): void {
                Context::complement(
                    $query,
                    static function (Builder $positive) use ($predicate): void {
                        if ($predicate instanceof Closure) {
                            $predicate($positive);
                        } else {
                            $positive->scopes($predicate);
                        }
                    },
                );
            };
        }

        if (count($predicates) !== count($values)) {
            throw new LogicException(
                'scopes() requires a predicate for every allowed field value.',
            );
        }

        $this->scopes = $predicates;

        $this->operators('=', '!=')->withoutRanges()->withoutWildcards()->withoutExists();

        return $this;
    }

    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    public function sumOf(string $relation, string $column, null|Closure $constraint = null): static
    {
        return $this->aggregate('sum', $relation, $column, $constraint);
    }

    /**
     * @param Builder<*> $query
     *
     * @return non-empty-list<Expression|string>
     */
    public function targets(Builder $query): array
    {
        if ($this->aggregate instanceof Aggregate) {
            return [$this->aggregate->target($query->getModel(), $this->emptyValue)];
        }

        if ($this->expression instanceof Closure) {
            return [($this->expression)($query->getModel()->getConnection()->getDriverName())];
        }

        return array_map(static function (string $column) use ($query): string {
            $parts = explode('->', $column);
            $parts[0] = $query->qualifyColumn($parts[0]);

            return implode('->', $parts);
        }, $this->columns ?? [$this->getInternalName()]);
    }

    /**
     * @param Closure(Builder<*>, Clause, Context): void|Resolver $resolver
     */
    public function using(Closure|Resolver $resolver): static
    {
        $this->assertNoRelation('using()');

        $this->resolver = $resolver;

        return $this;
    }

    public function whenEmpty(float|int|string $value): static
    {
        if (! $this->aggregate instanceof Aggregate || $this->aggregate->function === 'count') {
            throw new LogicException('whenEmpty() requires a non-count aggregate.');
        }

        if (! $this->isDate() && ! is_numeric($value)) {
            throw new LogicException('A numeric aggregate requires a numeric whenEmpty() value.');
        }

        // The stored format of a date depends on the related model, so a search checks the text.
        if ($this->isDate() && ! is_string($value)) {
            throw new LogicException('A date aggregate requires a whenEmpty() date string.');
        }

        $this->emptyValue = $value;

        return $this;
    }

    public function withTrashedRelated(): static
    {
        $this->trashedRelated = true;

        return $this;
    }

    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    private function aggregate(
        string $function,
        string $relation,
        string $column,
        null|Closure $constraint,
    ): static {
        $this->assertNoColumnTarget($function.'Of()');

        $types = match ($function) {
            'count' => ['integer'],
            'min', 'max' => ['numeric', 'decimal', 'date'],
            default => ['numeric', 'decimal'],
        };

        if (! in_array($this->getValueType(), $types, true)) {
            throw new LogicException($function.'Of() requires a '.implode(', ', $types).' field.');
        }

        if (
            $column === ''
            || $relation === ''
            || str_contains($relation, '.')
        ) {
            throw new LogicException(
                'Aggregates require one root relation and an aggregate column.',
            );
        }

        if ($this->isPattern()) {
            throw new LogicException('Aggregates require an exact scalar field.');
        }

        $this->aggregate = new Aggregate($function, $relation, $column, $constraint);

        return $this;
    }

    private function assertNoColumnTarget(string $method): void
    {
        $this->assertNoRelation($method);

        if (
            $this->columns !== null
            || $this->expression instanceof Closure
            || $this->resolver !== null
        ) {
            throw new LogicException($method.' cannot be combined with column targets or using().');
        }
    }

    private function assertNoComputedTarget(string $method): void
    {
        if ($this->aggregate instanceof Aggregate || $this->scopes !== null) {
            throw new LogicException(
                'Aggregate and scope targets cannot be combined with '.$method.'.',
            );
        }
    }

    private function assertNoRelation(string $method): void
    {
        $this->assertNoComputedTarget($method);

        if ($this->relation !== null) {
            throw new LogicException('relation() cannot be combined with '.$method.'.');
        }
    }

    /**
     * @return Closure(Builder<*>): void|string
     */
    private function scopeCallback(mixed $predicate): Closure|string
    {
        if (! $predicate instanceof Closure && ! is_string($predicate)) {
            throw new LogicException('Scope predicates must be local scope names or closures.');
        }

        return $predicate;
    }

    private function scopeKey(bool|float|int|string $value): string
    {
        return get_debug_type($value).':'.$value;
    }
}
