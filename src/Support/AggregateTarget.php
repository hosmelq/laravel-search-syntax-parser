<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Override;

/**
 * @internal
 */
final readonly class AggregateTarget implements Expression
{
    public function __construct(
        private Builder $query,
        private null|float|int|string $empty,
    ) {}

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return [...$this->query->getBindings(), ...($this->empty === null ? [] : [$this->empty])];
    }

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        $sql = '('.$this->query->toSql().')';
        $fallback = is_numeric($this->empty)
            && ($grammar instanceof SQLiteGrammar || $grammar instanceof PostgresGrammar)
            ? 'cast(? as numeric)'
            : '?';

        return $this->empty === null ? $sql : 'coalesce('.$sql.', '.$fallback.')';
    }
}
