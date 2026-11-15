<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Query\Bounds;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use HosmelQ\SearchSyntaxParser\Query\TextPattern;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Override;

/**
 * @internal
 */
final readonly class ColumnPredicate implements Expression
{
    public function __construct(
        private ColumnTarget|string $target,
        private Clause $clause,
        private Context $context,
        private null|string $jsonType = null,
    ) {}

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return [
            ...($this->target instanceof ColumnTarget ? $this->target->bindings() : []),
            ...$this->values(),
        ];
    }

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        $column = $this->target instanceof ColumnTarget
            ? $this->target->getValue($grammar)
            : '('.$this->target.')';

        if ($this->clause->text) {
            $column = match ($this->context->driver) {
                'pgsql' => 'lower(cast('.$column.' as text))',
                'sqlite' => $column.' COLLATE NOCASE',
                'mysql', 'mariadb' => $this->jsonType !== null ? 'lower('.$column.')' : $column,
                default => $column,
            };
        }

        $value = $this->clause->value;
        $parameters = array_map($this->parameter(...), $this->values());

        if ($value instanceof Bounds) {
            $not = $this->clause->operator === Operator::NotBetween;

            if ($value->from === null) {
                return $column.($not ? ' > ' : ' <= ').$parameters[0];
            }

            if ($value->to === null) {
                return $column.($not ? ' < ' : ' >= ').$parameters[0];
            }

            if ($this->jsonType === 'number' && $this->context->driver === 'mysql') {
                // MySQL BETWEEN coerces JSON numbers to text; ordinary comparisons retain their type.
                return '('.$column.' >= '.$parameters[0].' AND '.$column.' <= '.$parameters[1].')';
            }

            return $column
                .($not ? ' NOT BETWEEN ' : ' BETWEEN ')
                .$parameters[0]
                .' AND '
                .$parameters[1];
        }

        if (is_array($value)) {
            return $column
                .' '
                .$this->clause->operator->value
                .' ('
                .implode(', ', $parameters)
                .')';
        }

        $suffix = in_array($this->clause->operator, [Operator::Like, Operator::NotLike], true)
            ? " ESCAPE '!'"
            : '';

        return $column.' '.$this->clause->operator->value.' '.$parameters[0].$suffix;
    }

    private function parameter(bool|float|int|string $value): string
    {
        if ($this->clause->text) {
            return $this->context->driver === 'pgsql'
                || (
                    $this->jsonType !== null
                    && in_array($this->context->driver, ['mysql', 'mariadb'], true)
                )
                ? 'lower(?)'
                : '?';
        }

        if ($this->jsonType !== null && $this->jsonType !== 'string') {
            if ($this->context->driver === 'mysql') {
                return "json_extract(?, '$')";
            }

            if ($this->context->driver === 'pgsql' && $this->jsonType === 'boolean') {
                return 'cast(cast(? as integer) as boolean)';
            }

            return $this->context->driver === 'mariadb' ? '(? + 0)' : 'cast(? as numeric)';
        }

        if (! $this->context->field->comparesNumbers() || ! is_numeric($value)) {
            return '?';
        }

        if ($this->context->driver === 'pgsql' && is_int($value)) {
            return 'cast(? as bigint)';
        }

        if (in_array($this->context->driver, ['pgsql', 'sqlite'], true) && ! is_int($value)) {
            return 'cast(? as numeric)';
        }

        return '?';
    }

    /**
     * @return list<bool|float|int|string>
     */
    private function values(): array
    {
        $value = $this->clause->value;

        if ($value instanceof TextPattern) {
            return [$value->toLike()];
        }

        if ($value instanceof Bounds) {
            return array_values(
                array_filter(
                    [$value->from, $value->to],
                    static fn (null|bool|float|int|string $bound): bool => $bound !== null,
                ),
            );
        }

        if (is_array($value)) {
            return $value;
        }

        /** @var bool|float|int|string $value */
        return [$value];
    }
}
