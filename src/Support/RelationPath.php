<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Support;

use Closure;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * @internal
 */
final readonly class RelationPath
{
    public function __construct(private Field $field) {}

    /**
     * @template TModel of Model
     *
     * @param TModel $model
     *
     * @return Relation<Model, TModel, *>
     */
    public static function relation(Model $model, string $name): Relation
    {
        $method = [$model, $name];

        if (! $model->isRelation($name) || ! is_callable($method)) {
            throw new LogicException('Unknown relation method: '.$name);
        }

        $relation = Relation::noConstraints(Closure::fromCallable($method));

        if (! $relation instanceof Relation) {
            throw new LogicException(
                'Relation method "'.$name.'" must return an Eloquent relation.',
            );
        }

        return $relation;
    }

    public function assertValid(Model $model): void
    {
        $hasMorph = $this->validate($model, $this->segments());

        if (! $hasMorph && $this->field->getRelationTypes() !== []) {
            throw new LogicException('Relation types require a morphTo segment.');
        }
    }

    /**
     * @return non-empty-list<string>
     */
    public function segments(): array
    {
        /** @var string $relation */
        $relation = $this->field->getRelation();

        return explode('.', $relation);
    }

    /**
     * @param list<string> $segments
     */
    private function validate(Model $model, array $segments, bool $hasMorph = false): bool
    {
        if ($segments === []) {
            return $hasMorph;
        }

        $relation = self::relation($model, array_shift($segments));

        if (! $relation instanceof MorphTo) {
            return $this->validate($relation->getRelated(), $segments, $hasMorph);
        }

        if ($hasMorph) {
            throw new LogicException('A relation path may contain at most one morphTo segment.');
        }

        if ($this->field->getRelationTypes() === []) {
            throw new LogicException('A morphTo relation requires explicit model types.');
        }

        foreach ($this->field->getRelationTypes() as $type) {
            $this->validate(new $type(), $segments, true);
        }

        return true;
    }
}
