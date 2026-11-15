<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Exception\SearchSyntaxException;
use HosmelQ\SearchSyntaxParser\Laravel\Support\RelationPath;
use HosmelQ\SearchSyntaxParser\Laravel\Support\SortTarget;
use HosmelQ\SearchSyntaxParser\Query\ClauseGroup;
use HosmelQ\SearchSyntaxParser\SearchParser;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;
use HosmelQ\SearchSyntaxParser\Sorting\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonSerializable;
use Override;

class Search implements JsonSerializable
{
    private null|string $defaultSort = null;

    private string $errorKey;

    /**
     * @var list<Field>
     */
    private array $fields = [];

    private int $maxClauses;

    private int $maxDepth;

    private int $maxLength;

    private mixed $query = null;

    /**
     * @var list<AllowedSort|string>
     */
    private array $sorts = [];

    /**
     * @var list<string>
     */
    private array $terms = [];

    /**
     * @var null|Closure(): (null|DateTimeZone|string)|DateTimeZone
     */
    private null|Closure|DateTimeZone $timezone = null;

    final public function __construct()
    {
        $this->errorKey = config()->string('search-syntax-parser.error_key');
        $this->maxClauses = config()->integer('search-syntax-parser.max_clauses');
        $this->maxDepth = config()->integer('search-syntax-parser.max_depth');
        $this->maxLength = config()->integer('search-syntax-parser.max_length');
    }

    public static function make(): static
    {
        return new static();
    }

    public function defaultSort(string $sort): static
    {
        $this->defaultSort = $sort;

        return $this;
    }

    public function errorKey(string $key): static
    {
        $this->errorKey = $key;

        return $this;
    }

    /**
     * @param list<mixed> $fields
     */
    public function fields(array $fields): static
    {
        $definitions = [];

        foreach ($fields as $field) {
            if (! $field instanceof Field) {
                throw new InvalidArgumentException(
                    'Search fields must be package Field instances.',
                );
            }

            $definitions[] = $field;
        }

        $this->fields = $definitions;

        return $this;
    }

    /**
     * @return array{
     *     fields: list<array<string, mixed>>,
     *     sorts: list<array{direction: 'asc'|'desc', name: string}>,
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return SearchParser::query('')
            ->allowedFields($this->fields)
            ->allowedSorts($this->sorts)
            ->jsonSerialize();
    }

    public function maxClauses(int $clauses): static
    {
        $this->maxClauses = $clauses;

        return $this;
    }

    public function maxDepth(int $depth): static
    {
        $this->maxDepth = $depth;

        return $this;
    }

    public function maxLength(int $characters): static
    {
        $this->maxLength = $characters;

        return $this;
    }

    public function query(mixed $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @param list<AllowedSort|string> $sorts
     */
    public function sorts(array $sorts): static
    {
        $this->sorts = $sorts;

        return $this;
    }

    /**
     * @param list<string> $fieldNames
     */
    public function terms(array $fieldNames): static
    {
        $this->terms = $fieldNames;

        return $this;
    }

    /**
     * @param Closure(): (null|DateTimeZone|string)|DateTimeZone|string $timezone
     */
    public function timezone(Closure|DateTimeZone|string $timezone): static
    {
        $this->timezone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;

        return $this;
    }

    private function validationError(SearchSyntaxException $exception): never
    {
        $translator = app(Translator::class);
        $message = $translator->get(
            'search-syntax-parser::errors.'.$exception->getMessageKey(),
            $exception->getMessageParameters(),
        );

        if ($exception->getPosition() !== null) {
            $message .= ' '.$translator->get('search-syntax-parser::errors.position', [
                'position' => $exception->getPosition(),
            ]);
        }

        throw ValidationException::withMessages([$this->errorKey => $message]);
    }

    /**
     * @param Builder<*> $query
     */
    public function __invoke(Builder $query): void
    {
        $parser = SearchParser::query($this->query)
            ->allowedFields($this->fields)
            ->terms($this->terms);

        foreach ($this->fields as $field) {
            $field->assertValidQuery($query);
            $field->assertValidScopes($query->getModel());

            $field->assertValidAggregate($query);

            if ($field->getRelation() !== null) {
                new RelationPath($field)->assertValid($query->getModel());
            }
        }

        $timezone = $this->timezone instanceof Closure ? ($this->timezone)() : $this->timezone;
        $timezone ??= config()->string('app.timezone');
        $timezone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;
        $now = DateTimeImmutable::createFromInterface(Date::now());

        try {
            $parser->allowedSorts($this->sorts)
                ->maxLength($this->maxLength)
                ->maxDepth($this->maxDepth)
                ->maxClauses($this->maxClauses);

            if ($this->defaultSort !== null) {
                $parser->defaultSort($this->defaultSort);
            }

            $clauses = $parser->compile();
            $compiler = new Compiler($parser, $timezone, $now);

            $compiler->applyDirectives($query);

            if ($clauses instanceof ClauseGroup) {
                $compiler->apply($query, $clauses);
            }

            $sort = $parser->getSort();

            if ($sort instanceof Sort) {
                $parts = explode('->', $sort->getInternalName());
                $parts[0] = $query->qualifyColumn($parts[0]);
                $column = implode('->', $parts);
                $driver = $query->getModel()->getConnection()->getDriverName();

                $query->orderBy(new SortTarget($column, $driver, nullOrder: true));

                // MariaDB extracts JSON as text, so numbers need numeric sort keys: doubles keep
                // their magnitude, and decimals break ties between integers beyond double precision.
                if ($driver === 'mariadb' && str_contains($column, '->')) {
                    foreach (['double', 'decimal(65, 30)'] as $cast) {
                        $query->orderBy(
                            new SortTarget($column, $driver, numericCast: $cast),
                            $sort->getDirection(),
                        );
                    }
                }

                $query->orderBy(new SortTarget($column, $driver), $sort->getDirection());
            }
        } catch (SearchSyntaxException $searchSyntaxException) {
            $this->validationError($searchSyntaxException);
        }
    }
}
