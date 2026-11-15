<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Override;

/**
 * @internal
 */
final readonly class SortTarget implements Expression
{
    public function __construct(
        private string $target,
        private string $driver,
        private bool $nullOrder = false,
        private null|string $numericCast = null,
    ) {}

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        if (str_contains($this->target, '->')) {
            $json = new JsonTarget($this->target, $grammar, $this->driver);

            if ($this->numericCast !== null) {
                return 'case when '.$json->type()." in ('integer', 'double') then cast("
                    .$json->text().' as '.$this->numericCast.') end';
            }

            $value = $this->driver === 'pgsql' ? 'cast('.$json->raw().' as jsonb)' : $json->raw();
            $sql = 'case when '.$json->type()." = 'null' then null else ".$value.' end';
        } else {
            $sql = $grammar->wrap($this->target);
        }

        return $this->nullOrder ? '('.$sql.') is null' : $sql;
    }
}
