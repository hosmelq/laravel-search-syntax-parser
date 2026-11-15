<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Illuminate\Database\Grammar;

/**
 * @internal
 */
final readonly class JsonTarget
{
    /**
     * @param null|string $key the dynamic key that replaces the target's * segment
     */
    public function __construct(
        private string $target,
        private Grammar $grammar,
        private string $driver,
        private null|string $key = null,
    ) {}

    /**
     * @return array{from: string, raw: string, text: string, type: string}
     */
    public function elements(): array
    {
        $raw = $this->raw();
        $array = 'case when '.$this->type()." = 'array' then ".$raw.' else ';

        if ($this->driver === 'pgsql') {
            return [
                'from' => 'jsonb_array_elements(case when '
                    .$this->type()
                    ." = 'array' then cast("
                    .$raw
                    ." as jsonb) else '[]'::jsonb end) as search_element(value)",
                'raw' => 'search_element.value',
                'text' => "(search_element.value #>> '{}')",
                'type' => 'jsonb_typeof(search_element.value)',
            ];
        }

        if ($this->driver === 'sqlite') {
            return [
                'from' => 'json_each('.$array."'[]' end) as search_element",
                'raw' => 'search_element.value',
                'text' => 'search_element.value',
                'type' => 'search_element.type',
            ];
        }

        $element = 'json_extract('.$raw.", concat('$[', search_element.ordinality - 1, ']'))";

        return [
            'from' => 'json_table('
                .$array
                ."json_array() end, '$[*]' columns (ordinality for ordinality)) as search_element",
            'raw' => $element,
            'text' => 'json_unquote('.$element.')',
            'type' => 'lower(json_type('.$element.'))',
        ];
    }

    public function raw(): string
    {
        if (! str_contains($this->target, '->')) {
            return $this->grammar->wrap($this->target);
        }

        if ($this->driver === 'pgsql') {
            return $this->key === null
                ? str_replace('->>', '->', $this->grammar->wrap($this->target))
                : $this->dynamicPostgresPath($this->key);
        }

        if ($this->driver === 'mariadb' && $this->key !== null) {
            return $this->dynamicMariaDbPath($this->key);
        }

        $selector = $this->grammar->wrap($this->selector());

        return match ($this->driver) {
            'sqlite' => $selector,
            'mariadb' => 'json_extract('.mb_substr($selector, mb_strlen('json_value(')),
            default => mb_substr($selector, mb_strlen('json_unquote('), -1),
        };
    }

    public function text(): string
    {
        if ($this->driver === 'pgsql' && $this->key !== null) {
            return '('.$this->raw()." #>> '{}')";
        }

        if ($this->driver === 'mariadb' && str_contains($this->target, '->')) {
            return 'json_unquote('.$this->raw().')';
        }

        return $this->grammar->wrap($this->selector());
    }

    public function type(): string
    {
        if ($this->driver === 'pgsql') {
            return 'jsonb_typeof(cast('.$this->raw().' as jsonb))';
        }

        if ($this->driver === 'sqlite') {
            return str_contains($this->target, '->')
                ? 'json_type('.mb_substr($this->raw(), mb_strlen('json_extract('))
                : 'json_type('.$this->raw().')';
        }

        return 'lower(json_type('.$this->raw().'))';
    }

    private function dynamicMariaDbPath(string $key): string
    {
        $segments = explode('->', $this->target);
        $index = (int) array_search('*', $segments, true);
        $prefix = implode('->', array_slice($segments, 0, $index));
        $suffix = array_slice($segments, $index + 1);
        $base = new self($prefix, $this->grammar, $this->driver)->raw();

        // MariaDB paths do not resolve escaped Unicode keys or quoted negative keys consistently.
        // JSON_KEYS() drops repeated keys that $.* keeps, so an object that repeats a key reads as
        // missing the key instead of reading another key's value.
        $path = '(select json_extract(json_extract('.$base.", '$.*'), "
            ."concat('$[', search_key.ordinality - 1, ']')) from json_table(json_keys("
            .$base."), '$[*]' columns (ordinality for ordinality, name longtext path '$')) "
            .'as search_key where cast(search_key.name as binary) = cast('
            .$this->grammar->escape($key).' as binary) and json_length('.$base
            .') = json_length(json_keys('.$base.')))';

        if ($suffix === []) {
            return $path;
        }

        // Compile the fixed segments after the key, such as items[0], through a placeholder column.
        $placeholder = $this->grammar->wrap('search_json');
        $rest = new self('search_json->'.implode('->', $suffix), $this->grammar, $this->driver)
            ->raw();

        return str_replace($placeholder, $path, $rest);
    }

    private function dynamicPostgresPath(string $key): string
    {
        $segments = explode('->', $this->target);
        $index = (int) array_search('*', $segments, true);
        $prefix = implode('->', array_slice($segments, 0, $index));
        $suffix = array_slice($segments, $index + 1);
        $base = $index === 1
            ? $this->grammar->wrap($prefix)
            : str_replace('->>', '->', $this->grammar->wrap($prefix));

        // A text operand reads only object members: the grammar would turn a key such as 0 or +5
        // into an array index.
        $path = '(cast('.$base.' as jsonb) -> cast('.$this->grammar->escape($key).' as text))';

        if ($suffix === []) {
            return $path;
        }

        // Compile the fixed segments after the key, such as items[0], through a placeholder column.
        $placeholder = $this->grammar->wrap('search_json');
        $rest = str_replace(
            '->>',
            '->',
            $this->grammar->wrap('search_json->'.implode('->', $suffix)),
        );

        return '('.$path.mb_substr($rest, mb_strlen($placeholder)).')';
    }

    private function selector(): string
    {
        $key = $this->key;

        if ($key === null) {
            return $this->target;
        }

        return implode('->', array_map(
            static fn (string $segment): string => $segment === '*' ? $key : $segment,
            explode('->', $this->target),
        ));
    }
}
