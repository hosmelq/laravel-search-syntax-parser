<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Rules;

use Closure;
use HosmelQ\SearchSyntaxParser\Laravel\Concerns\HasSearchSyntax;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Override;

final readonly class SearchSyntax implements ValidationRule
{
    public bool $implicit;

    /**
     * @param class-string<Model> $model
     * @param null|Closure(Search): (Search|void) $configure
     */
    public function __construct(private string $model, private null|Closure $configure = null)
    {
        $this->implicit = true;

        if (
            ! is_subclass_of($model, Model::class)
            || ! in_array(HasSearchSyntax::class, class_uses_recursive($model), true)
        ) {
            throw new InvalidArgumentException(
                'SearchSyntax requires an Eloquent model using HasSearchSyntax.',
            );
        }
    }

    /**
     * @param Closure(string): mixed $fail
     */
    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            new $this->model()->newQuery()->whereSearch($value, $this->configure)->toSql();
        } catch (ValidationException $validationException) {
            foreach ($validationException->validator->errors()->all() as $message) {
                $fail($message);
            }
        }
    }
}
