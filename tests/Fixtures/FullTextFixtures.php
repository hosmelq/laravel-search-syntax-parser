<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Workbench\App\Models\Product;

final class FullTextFixtures
{
    public static function ids(string $input, bool $terms = false, bool $multiple = false): array
    {
        $field = Field::string('text', 'description')->words()->nullable()->likeFallback();

        if ($multiple) {
            $field->columns(['name', 'description']);
        }

        return Product::query()
            ->tap(Search::make()->fields([$field])->terms($terms ? ['text'] : [])->query($input))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function seed(): void
    {
        foreach ([
            ['coffee ceramic', 'Fresh coffee ceramic cups'],
            ['Other', 'Ceramic fresh coffee cups'],
            ['Other', 'Fresh coffeemaker ceramic cups'],
            ['coffee', null],
            ['Other', 'Fresh espresso cups'],
            ['Other', 'Coffee ceramic cups'],
        ] as $index => [$name, $description]) {
            Product::query()->create([
                'id' => $index + 1,
                'name' => $name,
                'description' => $description,
                'status' => 'open',
            ]);
        }
    }
}
