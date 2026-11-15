<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Override;

/**
 * @internal
 */
final readonly class SelfRelation implements Expression
{
    private function __construct(private string $table, private string $alias) {}

    /**
     * Derived tables let MySQL update or delete the root table without error 1093.
     * Run it only once the query is built: Eloquent compares sources to alias nested self relations.
     */
    public static function materialize(Builder $query, string $root, bool $subquery = true): void
    {
        if ($subquery) {
            $alias = is_string($query->from) ? self::alias($query->from, $root) : null;

            if ($alias !== null) {
                $query->fromSub(
                    static function (Builder $table) use ($root): void {
                        $table->from($root);
                    },
                    $alias,
                );
            }

            /** @var list<JoinClause> $joins */
            $joins = $query->joins ?? [];

            foreach ($joins as $join) {
                $alias = is_string($join->table) ? self::alias($join->table, $root) : null;

                if ($alias !== null) {
                    $join->table = new self($root, $alias);
                }

                self::materialize($join, $root, false);
            }
        }

        /** @var list<array{type: string, query?: mixed}> $wheres */
        $wheres = $query->wheres;

        foreach ($wheres as $where) {
            // Nested groups compile inline; every other where with a query is a subquery.
            if (($where['query'] ?? null) instanceof Builder) {
                self::materialize($where['query'], $root, $where['type'] !== 'Nested');
            }
        }
    }

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        return '(select * from '.$grammar->wrapTable($this->table).') as '
            .$grammar->wrapTable($this->alias);
    }

    private static function alias(string $table, string $root): null|string
    {
        if ($table === $root) {
            return $root;
        }

        $position = mb_stripos($table, ' as ');

        if ($position === false || mb_substr($table, 0, $position) !== $root) {
            return null;
        }

        return mb_trim(mb_substr($table, $position + 4));
    }
}
