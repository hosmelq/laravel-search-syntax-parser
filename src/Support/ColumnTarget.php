<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Override;

/**
 * @internal
 */
final readonly class ColumnTarget implements Expression
{
    public function __construct(private Expression|string $target) {}

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->target instanceof AggregateTarget ? $this->target->bindings() : [];
    }

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->target);

        return $this->target instanceof Expression ? '('.$column.')' : $column;
    }
}
