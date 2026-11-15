<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * @internal
 */
final readonly class Aggregate
{
    /**
     * @param null|Closure(Builder<*>): void $constraint
     */
    public function __construct(
        public string $function,
        private string $relation,
        private string $column,
        private null|Closure $constraint,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param TModel $model
     *
     * @return Relation<Model, TModel, *>
     */
    public function relation(Model $model): Relation
    {
        $relation = RelationPath::relation($model, $this->relation);

        if ($relation instanceof MorphTo) {
            throw new LogicException('Aggregates do not support morphTo relations.');
        }

        return $relation;
    }

    public function target(Model $model, null|float|int|string $empty): AggregateTarget
    {
        $query = $model->newModelQuery();
        $relation = $this->relation($model);
        $column = $this->column;

        if ($column !== '*' && ! str_contains($column, '.')) {
            $column = $query->getQuery()->from === $relation->getQuery()->getQuery()->from
                ? $relation->getRelationCountHash(false).'.'.$column
                : $relation->getRelated()->qualifyColumn($column);
        }

        $related = $relation->getRelationExistenceQuery(
            $relation->getRelated()->newQuery(),
            $query,
            [],
        );
        $related->getQuery()->aggregate = ['function' => $this->function, 'columns' => [$column]];

        if ($this->constraint instanceof Closure) {
            $this->constrain($related, $this->constraint);
        }

        $related->mergeConstraintsFrom($relation->getQuery());

        $aggregate = $related->toBase()->reorder();

        // The aggregate replaces the projection, so selects that constraints or related global
        // scopes add go, with their bindings.
        $aggregate->columns = null;
        $aggregate->bindings['select'] = [];

        SelfRelation::materialize($aggregate, $model->getTable());

        return new AggregateTarget($aggregate, $empty);
    }

    /**
     * @param Builder<*> $related
     * @param Closure(Builder<*>): void $constraint
     */
    private function constrain(Builder $related, Closure $constraint): void
    {
        $query = $related->getQuery();
        $count = count($query->wheres);

        $constraint($related);

        if (($query->groups ?? []) !== [] || ($query->havings ?? []) !== []) {
            throw new LogicException('Aggregate constraints cannot group rows.');
        }

        if ($query->limit !== null || $query->offset !== null) {
            throw new LogicException('Aggregate constraints cannot limit rows.');
        }

        $added = array_splice($query->wheres, $count);

        if ($added === []) {
            return;
        }

        // The added conditions keep their bindings, already in order on the aggregate query.
        $group = $query->forNestedWhere();
        $group->wheres = $added;
        $query->wheres[] = ['type' => 'Nested', 'query' => $group, 'boolean' => 'and'];
    }
}
