<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Query\TextPattern;
use HosmelQ\SearchSyntaxParser\Support\FullText;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Override;

/**
 * @internal
 */
final readonly class FullTextPredicate implements Expression
{
    public function __construct(
        private ColumnTarget $target,
        private FullText $match,
        private Context $context,
        private bool $negated = false,
    ) {}

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        $match = $this->match;

        return match ($this->context->driver) {
            'sqlite' => array_map(
                static fn (string $word): string => new TextPattern(['', $word, ''])->toLike(),
                $match->words,
            ),
            'pgsql' => [$this->postgres($match->words)],
            default => [$this->mysql($match->words)],
        };
    }

    #[Override]
    public function getValue(Grammar $grammar): string
    {
        $match = $this->match;
        $column = $this->target->getValue($grammar);
        $config = $this->context->driver === 'pgsql'
            ? $grammar->escape($this->context->field->getFullTextLanguage()).'::regconfig'
            : '';
        $sql = match ($this->context->driver) {
            'sqlite' => implode(
                ' AND ',
                array_fill(0, count($match->words), $column." LIKE ? ESCAPE '!'"),
            ),
            'pgsql' => 'to_tsvector('.$config.', '.$column.') @@ to_tsquery('.$config.', ?)',
            default => 'MATCH ('.$column.') AGAINST (? IN BOOLEAN MODE)',
        };

        return ($this->negated ? 'NOT ' : '').'('.$sql.')';
    }

    /**
     * @param list<string> $words
     */
    private function mysql(array $words): string
    {
        if ($this->match->phrase && ! $this->match->prefix) {
            return '+"'.implode(' ', $words).'"';
        }

        $words = array_map(static fn (string $word): string => '+'.$word, $words);

        return implode(' ', $words).($this->match->prefix ? '*' : '');
    }

    /**
     * @param list<string> $words
     */
    private function postgres(array $words): string
    {
        $words = array_map(static fn (string $word): string => "'".$word."'", $words);

        return implode($this->match->phrase ? ' <-> ' : ' & ', $words)
            .($this->match->prefix ? ':*' : '');
    }
}
