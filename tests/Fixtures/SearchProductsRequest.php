<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Rules\SearchSyntax;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Foundation\Http\FormRequest;
use Workbench\App\Models\SoftProduct;

class SearchProductsRequest extends FormRequest
{
    /**
     * @return array<string, list<SearchSyntax>>
     */
    public function rules(): array
    {
        return [
            'filter' => [
                new SearchSyntax(SoftProduct::class, static fn (Search $search): Search => $search
                    ->fields([
                        Field::string('name'),
                        Field::date('created', internalName: 'created_at'),
                        Field::string('secret')->authorize(static fn (): bool => false),
                        Field::trashed(),
                    ])
                    ->terms(['name'])
                    ->sorts(['name'])
                    ->maxLength(100)
                    ->maxClauses(2)
                    ->maxDepth(2)
                    ->errorKey('internal')),
            ],
        ];
    }
}
