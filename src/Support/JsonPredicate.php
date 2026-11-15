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
final readonly class JsonPredicate implements Expression
{
    public function __construct(
        private string $target,
        private Clause $clause,
        private Context $context,
    ) {}

    /**
     * @return list<bool|float|int|string>
     */
    public function bindings(): array
    {
        $value = $this->clause->value;

        if ($value instanceof TextPattern) {
            return [$value->toLike()];
        }

        if ($value === null || $this->hasMixedRangeTypes()) {
            return [];
        }

        $values = $value instanceof Bounds
            ? array_values(
                array_filter(
                    [$value->from, $value->to],
                    static fn (null|bool|float|int|string $bound): bool => $bound !== null,
                ),
            )
            : (is_array($value) ? $value : [$value]);

        return array_map(function (bool|float|int|string $value): bool|float|int|string {
            if (
                $this->context->driver === 'mysql'
                && $this->context->field->comparisonType($value) !== 'string'
            ) {
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                if (! is_finite((float) $value)) {
                    // MySQL rejects JSON numbers outside its double range. JSON null sorts
                    // below every number, and JSON strings above them, so these bounds keep
                    // comparisons correct without parsing an overflowing JSON number.
                    return str_starts_with((string) $value, '-') ? 'null' : '""';
                }

                return (string) $value;
            }

            return $value;
        }, $values);
    }

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        $target = new JsonTarget(
            $this->target,
            $grammar,
            $this->context->driver,
            $this->context->field->keyOf($this->clause->field),
        );
        $operator = $this->clause->positive()->operator;
        $exists = $operator === Operator::Exists;

        if ($this->context->field->isJsonArray()) {
            $elements = $target->elements();
            $predicate = $exists
                ? '1 = 1'
                : $this->scalar(
                    $grammar,
                    $elements['raw'],
                    $elements['text'],
                    $elements['type'],
                    $operator,
                );
            $sql = 'exists (select 1 from '.$elements['from'].' where '.$predicate.')';
        } else {
            $sql = $exists
                ? $target->type().' is not null and '.$target->type()." <> 'null'"
                : $this->scalar(
                    $grammar,
                    $target->raw(),
                    $target->text(),
                    $target->type(),
                    $operator,
                );
        }

        // Keep JSON_TABLE correlated on MySQL instead of letting EXISTS become a semijoin.
        // COALESCE also makes missing scalar paths participate in set complements.
        return ($this->clause->negated ? 'not ' : '').'(coalesce(('.$sql.'), false))';
    }

    private function hasMixedRangeTypes(): bool
    {
        $value = $this->clause->value;

        return $value instanceof Bounds
            && $value->from !== null
            && $value->to !== null
            && $this->context->field->comparisonType(
                $value->from,
            ) !== $this->context->field->comparisonType(
                $value->to,
            );
    }

    private function scalar(
        Grammar $grammar,
        string $raw,
        string $text,
        string $type,
        Operator $operator,
    ): string {
        if ($this->hasMixedRangeTypes()) {
            return '1 = 0';
        }

        $values = is_array($this->clause->value) ? $this->clause->value : [$this->clause->value];
        $predicates = [];

        foreach ($values as $value) {
            /** @var bool|float|int|string $sample */
            $sample = match (true) {
                $value instanceof Bounds => $value->from ?? $value->to,
                $value instanceof TextPattern => $value->toLike(),
                default => $value,
            };
            $jsonType = $this->context->field->comparisonType($sample);
            $guard = match ($jsonType) {
                'number' => $type
                    ." in ('number', 'integer', 'unsigned integer', 'real', 'double', 'decimal')",
                'boolean' => $type." in ('boolean', 'true', 'false')",
                default => $type." in ('string', 'text')",
            };
            $column = match ($jsonType) {
                'number' => match ($this->context->driver) {
                    'mysql' => $raw,
                    'mariadb' => 'case when '.$guard.' then ('.$text.' + 0) end',
                    default => 'case when '.$guard.' then cast('.$text.' as numeric) end',
                },
                'boolean' => match ($this->context->driver) {
                    'mysql' => $raw,
                    'pgsql' => 'case when '.$guard.' then cast('.$text.' as boolean) end',
                    'mariadb' => 'case when '.$text." = 'true' then 1 else 0 end",
                    default => $text,
                },
                default => in_array(
                    $this->context->driver,
                    ['mysql', 'mariadb'],
                    true,
                ) && ! $this->clause->text
                    ? 'cast('.$text.' as binary)'
                    : $text,
            };
            $clause = new Clause(
                $this->clause->field,
                is_array($this->clause->value) ? Operator::Equal : $operator,
                $value,
                text: $this->clause->text,
            );
            $predicate = new ColumnPredicate($column, $clause, $this->context, $jsonType);
            $predicates[] = '('.$guard.' and '.$predicate->getValue($grammar).')';
        }

        return '('.implode(' or ', $predicates).')';
    }
}
