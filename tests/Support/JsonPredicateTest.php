<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\JsonFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Priority;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\RelationFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Status;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;

dataset('json types', [
    'string' => [
        Field::string('value'),
        'php',
        ['PHP', 'pHp', 'Laravel', 10, true, null, [], ['x' => 'php']],
        [1, 2],
    ],
    'integer' => [
        Field::integer('value'),
        '10',
        [10, 10.0, 20, '10', true, null, [], ['x' => 10]],
        [1, 2],
    ],
    'numeric' => [
        Field::numeric('value'),
        '10.5',
        [10.5, 10.50, 20, '10.5', true, null, [], ['x' => 10.5]],
        [1, 2],
    ],
    'decimal' => [
        Field::decimal('value', 0, 2),
        '10.50',
        [10.5, 10.50, 20, '10.50', true, null, [], ['x' => 10.5]],
        [1, 2],
    ],
    'boolean' => [
        Field::boolean('value'),
        'true',
        [true, false, 1, 0, 'true', null, [], ['x' => true]],
        [1],
    ],
    'UUID' => [
        Field::uuid('value'),
        '550E8400-E29B-41D4-A716-446655440001',
        [
            '550e8400-e29b-41d4-a716-446655440001',
            '550E8400-E29B-41D4-A716-446655440001',
            10,
            true,
            null,
            [],
            ['x' => 1],
        ],
        [1],
    ],
    'ULID' => [
        Field::ulid('value'),
        '01arz3ndektsv4rrffq69g5fav',
        [
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            '01arz3ndektsv4rrffq69g5fav',
            10,
            true,
            null,
            [],
            ['x' => 1],
        ],
        [1],
    ],
    'in' => [
        Field::in('value', ['open', 'closed']),
        'OPEN',
        ['open', 'OPEN', 'closed', 10, true, null, [], ['x' => 'open']],
        [1],
    ],
    'string enum' => [
        Field::enum('value', Status::class),
        'Open',
        ['open', 'OPEN', 'closed', 10, true, null, [], ['x' => 'open']],
        [1],
    ],
    'int enum' => [
        Field::enum('value', Priority::class),
        'Low',
        [10, 10.0, 20, '10', true, null, [], ['x' => 10]],
        [1, 2],
    ],
]);

it('resolves dynamic JSON keys as single path segments after the application key rule', function (
    bool $array,
    int $encoding,
): void {
    $keys = ['color', 'a.b', "o'clock", 'ñ', '123', '-0'];

    foreach (['PHP', 'Laravel', null] as $index => $value) {
        $meta = array_fill_keys($keys, $array ? [$value] : $value);
        $meta['Ñ'] = $array ? ['Other'] : 'Other';

        Product::query()->create([
            'id' => $index + 1,
            'name' => 'Product',
            'status' => 'open',
        ])->getConnection()->table('products')->where('id', $index + 1)->update([
            'meta' => json_encode($meta, $encoding | JSON_THROW_ON_ERROR),
        ]);
    }

    Product::query()->create(['id' => 4, 'name' => 'Missing', 'status' => 'open', 'meta' => []]);

    $field = Field::string('meta.*')->keys(
        static fn (string $key): bool => in_array($key, $keys, true),
    );

    $array ? $field->jsonArray('meta->*') : $field->column('meta->*');

    foreach ($keys as $key) {
        expect(JsonFixtures::ids('meta.'.$key.':php', [$field]))->toBe([1])
            ->and(JsonFixtures::ids('meta.'.$key.':!=php', [$field]))->toBe([2, 3, 4])
            ->and(JsonFixtures::ids('meta.'.$key.':php,laravel', [$field]))->toBe([1, 2])
            ->and(JsonFixtures::ids('meta.'.$key.':ph*', [$field]))->toBe([1]);
    }

    expect(fn (): array => JsonFixtures::ids('meta.private:php', [$field]))->toThrow(
        ValidationException::class,
    );
})->with([false, true])->with([0, JSON_UNESCAPED_UNICODE]);

it('accepts decimal bounds outside the database JSON number range without errors', function (
    bool $array,
): void {
    JsonFixtures::rows([-10, 0, 10, '10', null]);

    $field = Field::decimal('value', 0, 2);

    $array ? $field->jsonArray('tags') : $field->column('meta->value');

    $large = str_repeat('9', 400);

    foreach ([
        $large => [],
        '-'.$large => [],
        '<'.$large => [1, 2, 3],
        '>-'.$large => [1, 2, 3],
        '>'.$large => [],
        '<-'.$large => [],
        '-'.$large.'..'.$large => [1, 2, 3],
    ] as $input => $expected) {
        expect(JsonFixtures::ids('value:'.$input, [$field]))->toBe($expected)
            ->and(JsonFixtures::ids('NOT value:'.$input, [$field]))->toBe(
                array_values(array_diff(range(1, 7), $expected)),
            );
    }
})->with([false, true]);

it('applies storage maps and scales to scalar and array targets and typed terms', function (
    bool $array,
): void {
    JsonFixtures::rows([1_050, 2_000, 20, '10', '20', 'open', 'OPEN', null]);

    $fields = [
        Field::decimal('price', 0, 2)->scale(100),
        Field::in('level', ['low', 'high'])->map(['low' => 1_050, 'high' => 2_000]),
        Field::integer('code')->map([10 => '10']),
        Field::in('state', ['active'])->map(['active' => 'open']),
    ];

    foreach ($fields as $field) {
        $array ? $field->jsonArray('tags') : $field->column('meta->value');
    }

    expect(JsonFixtures::ids('price:10.50', $fields))->toBe([1])
        ->and(JsonFixtures::ids('price:>10.50', $fields))->toBe([2])
        ->and(JsonFixtures::ids('price:10.50,20', $fields))->toBe([1, 2])
        ->and(JsonFixtures::ids('price:10.50..20', $fields))->toBe([1, 2])
        ->and(JsonFixtures::ids('10.50', $fields, ['price']))->toBe([1])
        ->and(JsonFixtures::ids('level:LOW', $fields))->toBe([1])
        ->and(JsonFixtures::ids('code:10', $fields))->toBe([4])
        ->and(JsonFixtures::ids('code:20', $fields))->toBe([5])
        ->and(JsonFixtures::ids('state:active', $fields))->toBe([6]);
})->with([false, true]);

it('combines JSON and ordinary columns without escaping the surrounding group', function (): void {
    JsonFixtures::rows(['PHP', 'Laravel', null]);

    Product::query()->findOrFail(2)->update(['description' => 'PHP']);
    Product::query()->create([
        'id' => 6,
        'name' => 'Outside',
        'status' => 'open',
        'tenant_id' => 2,
        'meta' => ['value' => 'PHP'],
    ]);

    $field = Field::string('value')->columns(['meta->value', 'description'])->nullable();

    expect(JsonFixtures::ids('value:php', [$field]))->toBe([1, 2])
        ->and(JsonFixtures::ids('value:!=php', [$field]))->toBe([3, 4, 5])
        ->and(JsonFixtures::ids('value:*', [$field]))->toBe([1, 2])
        ->and(JsonFixtures::ids('-value:*', [$field]))->toBe([3, 4, 5]);
});

it('compares false and mixed exact members by their stored JSON types', function (
    bool $array,
): void {
    JsonFixtures::rows([false, true, 0, 1, 'false', '0', 'open', 'OPEN', null]);

    $boolean = Field::boolean('active');
    $member = Field::in('member', [0, 'open']);

    foreach ([$boolean, $member] as $field) {
        $array ? $field->jsonArray('tags') : $field->column('meta->value');
    }

    expect(JsonFixtures::ids('active:false', [$boolean]))->toBe([1])
        ->and(JsonFixtures::ids('active:!=false', [$boolean]))->toBe(range(2, 11))
        ->and(JsonFixtures::ids('active:true,false', [$boolean]))->toBe([1, 2])
        ->and(JsonFixtures::ids('member:0,OPEN', [$member]))->toBe([3, 7])
        ->and(JsonFixtures::ids('member:!=0,OPEN', [$member]))->toBe([1, 2, 4, 5, 6, 8, 9, 10, 11])
        ->and(JsonFixtures::ids('member:0..open', [$member]))->toBeEmpty()
        ->and(JsonFixtures::ids('-member:0..open', [$member]))->toBe(range(1, 11));
})->with([false, true]);

it('compares fractional negative and large JSON numbers without narrowing them to integers', function (
    bool $array,
): void {
    JsonFixtures::rows([-10.5, 0.5, 10, 1_000_000_000_000, 1.0e100, '1e100', null]);

    $field = Field::numeric('value');

    $array ? $field->jsonArray('tags') : $field->column('meta->value');

    foreach ([
        '-10.5' => [1],
        '0.5' => [2],
        '1e100' => [5],
        '>-10' => [2, 3, 4, 5],
        '10..1000000000000' => [3, 4],
    ] as $input => $expected) {
        expect(JsonFixtures::ids('value:'.$input, [$field]))->toBe($expected)
            ->and(JsonFixtures::ids('-value:'.$input, [$field]))->toBe(
                array_values(array_diff(range(1, 9), $expected)),
            );
    }
})->with([false, true]);

it('compares only the configured JSON type and complements the full set', function (
    Field $definition,
    string $input,
    array $values,
    array $expected,
    bool $array,
): void {
    JsonFixtures::rows($values);

    $all = range(1, count($values) + 2);
    $complement = array_values(array_diff($all, $expected));
    $targets = $array ? ['tags', 'meta->value'] : ['meta->value', 'meta->nested->value'];

    if (Product::query()->getConnection()->getDriverName() === 'pgsql') {
        $targets[] = 'meta_jsonb->value';
    }

    foreach ($targets as $target) {
        $field = clone $definition;

        if ($array) {
            if ($target !== 'tags') {
                $attribute = explode('->', $target, 2)[0];

                Product::query()->each(function (Product $product) use ($attribute): void {
                    $document = $product->getAttribute($attribute);

                    if ($document !== null && array_key_exists('value', $document)) {
                        $product->update([$attribute => ['value' => [$document['value']]]]);
                    }
                });
            }

            $field->jsonArray($target);
        } else {
            $field->column($target);
        }

        expect(JsonFixtures::ids('value:'.$input, [$field]))->toBe($expected)
            ->and(JsonFixtures::ids('value:!='.$input, [$field]))->toBe($complement)
            ->and(JsonFixtures::ids('NOT value:'.$input, [$field]))->toBe($complement)
            ->and(JsonFixtures::ids('-value:'.$input, [$field]))->toBe($complement)
            ->and(JsonFixtures::ids('NOT value:!='.$input, [$field]))->toBe($expected)
            ->and(JsonFixtures::ids($input, [$field], ['value']))->toBe($expected)
            ->and(JsonFixtures::ids('-'.$input, [$field], ['value']))->toBe($complement);
    }
})->with('json types')->with([false, true]);

it('distinguishes JSON null missing keys and present wrong types for existence', function (): void {
    JsonFixtures::rows(['null', null, 0, false, [], ['x' => 1]]);

    $field = Field::string('value')->column('meta->value');

    expect(JsonFixtures::ids('value:*', [$field]))->toBe([1, 3, 4, 5, 6])
        ->and(JsonFixtures::ids('-value:*', [$field]))->toBe([2, 7, 8])
        ->and(JsonFixtures::ids('value:*..*', [$field]))->toBe([1, 3, 4, 5, 6])
        ->and(JsonFixtures::ids('-value:*..*', [$field]))->toBe([2, 7, 8]);
});

it('handles numeric operators lists and both range forms on JSON scalars and elements', function (
    bool $array,
): void {
    JsonFixtures::rows([0, 10, 20, '10', false, null, [], ['n' => 10]]);

    $field = Field::integer('value');
    $field = $array ? $field->jsonArray('tags') : $field->column('meta->value');
    $all = range(1, 10);

    foreach ([
        '0' => [1],
        '10' => [2],
        '>5' => [2, 3],
        '>10' => [3],
        '>=10' => [2, 3],
        '<10' => [1],
        '<=10' => [1, 2],
        '0,20' => [1, 3],
        '[10 TO 20]' => [2, 3],
        '10..20' => [2, 3],
        '*..10' => [1, 2],
        '20..*' => [3],
    ] as $input => $expected) {
        expect(JsonFixtures::ids('value:'.$input, [$field]))->toBe($expected)
            ->and(JsonFixtures::ids('NOT value:'.$input, [$field]))->toBe(
                array_values(array_diff($all, $expected)),
            );
    }

    expect(JsonFixtures::ids('value:!=0,20', [$field]))->toBe([2, 4, 5, 6, 7, 8, 9, 10])
        ->and(JsonFixtures::ids('value:10 value:20', [$field]))->toBeEmpty();
})->with([false, true]);

it('keeps fixed path segments around a dynamic key', function (): void {
    Product::query()->create([
        'id' => 1,
        'name' => 'Product',
        'status' => 'open',
        'meta' => [
            'groups' => [['color' => 'php']],
            'size' => ['value' => 'big', 'tags' => ['xl']],
        ],
    ]);

    $keys = static fn (string $key): bool => true;
    $group = Field::string('group.*')->keys($keys)->column('meta->groups[0]->*');
    $value = Field::string('option.*')->keys($keys)->column('meta->*->value');
    $tags = Field::string('tag.*')->keys($keys)->jsonArray('meta->*->tags');

    expect(JsonFixtures::ids('group.color:php', [$group]))->toBe([1])
        ->and(JsonFixtures::ids('group.color:laravel', [$group]))->toBeEmpty()
        ->and(JsonFixtures::ids('option.size:big', [$value]))->toBe([1])
        ->and(JsonFixtures::ids('option.groups:big', [$value]))->toBeEmpty()
        ->and(JsonFixtures::ids('tag.size:xl', [$tags]))->toBe([1])
        ->and(JsonFixtures::ids('-tag.size:xl', [$tags]))->toBeEmpty();
});

it('keeps JSON text comparisons case insensitive across modes and escaped LIKE patterns', function (
    bool $array,
): void {
    JsonFixtures::rows(['PHP Laravel', 'pHp', 'a%b a_b a!b a\\b', 'php%', 10, null]);

    $field = Field::string('value')->allowLeadingWildcards();
    $field = $array ? $field->jsonArray('tags') : $field->column('meta->value');
    $all = range(1, 8);

    foreach ([
        'PHP*' => [1, 2, 4],
        'p*LARAVEL' => [1],
        '*a%*' => [3],
        '*a_*' => [3],
        '*a!*' => [3],
        '*a\\b*' => [3],
        'php%,"PHP Laravel"' => [1, 4],
        '["php" TO "phpz"]' => [1, 2, 4],
    ] as $input => $expected) {
        expect(JsonFixtures::ids('value:'.$input, [$field]))->toBe($expected)
            ->and(JsonFixtures::ids('-value:'.$input, [$field]))->toBe(
                array_values(array_diff($all, $expected)),
            );
    }

    expect(JsonFixtures::ids('value:php', [(clone $field)->prefix()]))->toBe([1, 2, 4])
        ->and(JsonFixtures::ids('value:"php"', [(clone $field)->prefix()]))->toBe([2])
        ->and(JsonFixtures::ids('value:laravel', [(clone $field)->contains()]))->toBe([1])
        ->and(JsonFixtures::ids('value:laravel,"PHP"', [(clone $field)->contains()]))->toBe([1, 2])
        ->and(JsonFixtures::ids('value:!=laravel,"PHP"', [(clone $field)->contains()]))->toBe([
            3,
            4,
            5,
            6,
            7,
            8,
        ])
        ->and(JsonFixtures::ids('LARAVEL', [$field], ['value']))->toBe([1])
        ->and(JsonFixtures::ids('pHp*', [$field], ['value']))->toBe([1, 2, 4]);

    $query = Product::query()->tap(Search::make()->fields([$field])->query('value:a%_!*'));

    expect($query->toSql())->toContain("ESCAPE '!'")
        ->and($query->getBindings())->toBe(['a!%!_!!%']);
})->with([false, true]);

it('keeps typed JSON term eligibility and complement grouping across scalar and array fields', function (): void {
    JsonFixtures::rows([10, 'PHP', 'Laravel', null]);

    Product::query()->findOrFail(1)->update(['tags' => ['PHP']]);

    $fields = [
        Field::integer('number')->column('meta->value'),
        Field::string('tag')->jsonArray('tags'),
    ];

    expect(JsonFixtures::ids('php 10', $fields, ['number', 'tag']))->toBe([1])
        ->and(JsonFixtures::ids('NOT (php OR 10)', $fields, ['number', 'tag']))->toBe([3, 4, 5, 6])
        ->and(JsonFixtures::ids('coffee', $fields, ['number']))->toBeEmpty()
        ->and(JsonFixtures::ids('-coffee', $fields, ['number']))->toBe(range(1, 6))
        ->and(JsonFixtures::ids('10*', $fields, ['number']))->toBeEmpty();
});

it('never reads the value of another key from objects that repeat keys', function (
    bool $array,
): void {
    Product::query()->insert([
        'id' => 1,
        'name' => 'Product',
        'status' => 'open',
        'meta' => $array
            ? '{"a":["one"],"a":["three"],"b":["two"]}'
            : '{"a":"one","a":"three","b":"two"}',
    ]);

    $field = Field::string('meta.*')->keys(static fn (string $key): bool => true);

    $array ? $field->jsonArray('meta->*') : $field->column('meta->*');

    expect(JsonFixtures::ids('meta.b:three', [$field]))->toBeEmpty()
        ->and(JsonFixtures::ids('-meta.b:three', [$field]))->toBe([1]);
})->with([false, true]);

it('normalizes valid decimal notation for JSON number parameters without changing precision', function (): void {
    JsonFixtures::rows([0.5, 10, 10.5, -0.5]);

    $field = Field::decimal('value', 0, 2)->column('meta->value');

    foreach (['+.5' => [1], '00010.50' => [3], '10.' => [2], '-.5' => [4]] as $input => $expected) {
        expect(JsonFixtures::ids('value:'.$input, [$field]))->toBe($expected);
    }
});

it('reads dynamic keys only from objects, never as array indexes', function (bool $array): void {
    Product::query()->create([
        'id' => 1,
        'name' => 'Product',
        'status' => 'open',
        'meta' => $array ? [['php']] : ['php'],
    ]);

    $field = Field::string('meta.*')->keys(static fn (string $key): bool => true);

    $array ? $field->jsonArray('meta->*') : $field->column('meta->*');

    expect(JsonFixtures::ids('meta.0:php', [$field]))->toBeEmpty()
        ->and(JsonFixtures::ids('meta.+0:php', [$field]))->toBeEmpty()
        ->and(JsonFixtures::ids('meta.0:*', [$field]))->toBeEmpty()
        ->and(JsonFixtures::ids('-meta.0:php', [$field]))->toBe([1]);
})->with([false, true]);

it('reads numeric-looking dynamic keys as object keys', function (bool $array): void {
    $meta = ['5' => 'five', '+5' => 'plus', '0' => 'zero', '-0' => 'minus'];

    Product::query()->create([
        'id' => 1,
        'name' => 'Product',
        'status' => 'open',
        'meta' => $array
            ? array_map(static fn (string $value): array => [$value], $meta)
            : $meta,
    ]);

    $field = Field::string('meta.*')->keys(static fn (string $key): bool => true);

    $array ? $field->jsonArray('meta->*') : $field->column('meta->*');

    expect(JsonFixtures::ids('meta.+5:plus', [$field]))->toBe([1])
        ->and(JsonFixtures::ids('meta.+5:five', [$field]))->toBeEmpty()
        ->and(JsonFixtures::ids('meta.-0:minus', [$field]))->toBe([1])
        ->and(JsonFixtures::ids('meta.-0:zero', [$field]))->toBeEmpty()
        ->and(JsonFixtures::ids('meta.5:five', [$field]))->toBe([1])
        ->and(JsonFixtures::ids('meta.0:zero', [$field]))->toBe([1]);
})->with([false, true]);

it('reuses scalar and dynamic JSON predicates on the final related model', function (): void {
    RelationFixtures::seed();

    foreach ([
        1 => ['color' => 'PHP', 'size' => 10],
        2 => ['color' => 'Laravel', 'size' => '10'],
        3 => ['color' => null],
    ] as $id => $meta) {
        Product::query()->findOrFail($id)->lines()->create(['meta' => $meta]);
    }

    Product::query()->findOrFail(4)->lines()->create(['meta' => null]);

    $fields = [
        Field::string('color')->relation('lines', 'meta->color'),
        Field::integer('size')->relation('lines', 'meta->size'),
        Field::string('meta.*')->keys('/^[a-z]+$/')->relation('lines', 'meta->*'),
    ];

    foreach ([
        ['color:php', [1]],
        ['color:ph*', [1]],
        ['color:php,laravel', [1, 2]],
        ['color:*', [1, 2]],
        ['size:10', [1]],
        ['size:5..20', [1]],
        ['meta.color:php', [1]],
    ] as [$input, $expected]) {
        expect(RelationFixtures::ids($input, $fields))->toBe($expected)
            ->and(RelationFixtures::ids('NOT '.$input, $fields))->toBe(
                array_values(array_diff(range(1, 7), $expected)),
            );
    }
});

it('supports qualified JSON targets and dynamic placeholders inside a path', function (): void {
    Product::query()->create([
        'id' => 1,
        'name' => 'Product',
        'status' => 'open',
        'meta' => ['group' => ['color' => 'PHP']],
    ]);

    $field = Field::string('meta.*')->keys('/^[a-z]+$/')->column('products.meta->*->color');
    $builder = Product::query()
        ->join('products as peers', 'peers.id', '=', 'products.id')
        ->tap(Search::make()->fields([$field])->query('meta.group:php'));

    expect($builder->pluck('products.id')->all())->toBe([1]);
});

it('uses exact fields and the longest dynamic prefix in the same order as the core', function (): void {
    Product::query()->create([
        'id' => 1,
        'name' => 'Product',
        'status' => 'open',
        'meta' => [
            'value' => 'exact',
            'nested' => ['value' => 'deep'],
            'nested.value' => 'shallow',
        ],
    ]);

    $fields = [
        Field::string('meta.*')->keys('/.*/')->column('meta->*'),
        Field::string('meta.nested.*')->keys('/.*/')->column('meta->nested->*'),
        Field::string('meta.exact')->column('meta->value'),
    ];

    expect(JsonFixtures::ids('meta.exact:exact', $fields))->toBe([1])
        ->and(JsonFixtures::ids('meta.nested.value:deep', $fields))->toBe([1])
        ->and(JsonFixtures::ids('meta.nested.value:shallow', $fields))->toBeEmpty();
});

it('uses one array element per range and allows separate elements for repeated clauses', function (): void {
    foreach ([
        [0, 30],
        [10, 20],
        [15],
        [],
        null,
        '10',
        ['10'],
        [['n' => 15]],
        [null],
        [false],
    ] as $index => $tags) {
        Product::query()->create([
            'id' => $index + 1,
            'name' => 'Product',
            'status' => 'open',
            'tags' => $tags,
            'meta' => ['tags' => $tags],
        ]);
    }

    foreach (['tags', 'meta->tags'] as $target) {
        $field = Field::integer('value')->jsonArray($target);

        expect(JsonFixtures::ids('value:10..20', [$field]))->toBe([2, 3])
            ->and(JsonFixtures::ids('value:[10 TO 20]', [$field]))->toBe([2, 3])
            ->and(JsonFixtures::ids('value:>20 value:<5', [$field]))->toBe([1])
            ->and(JsonFixtures::ids('value:10 value:20', [$field]))->toBe([2])
            ->and(JsonFixtures::ids('value:10,30', [$field]))->toBe([1, 2])
            ->and(JsonFixtures::ids('value:!=10,30', [$field]))->toBe([3, 4, 5, 6, 7, 8, 9, 10])
            ->and(JsonFixtures::ids('value:*', [$field]))->toBe([1, 2, 3, 7, 8, 9, 10])
            ->and(JsonFixtures::ids('-value:*', [$field]))->toBe([4, 5, 6])
            ->and(JsonFixtures::ids('value:*..*', [$field]))->toBe([1, 2, 3, 7, 8, 9, 10])
            ->and(JsonFixtures::ids('-value:10..20', [$field]))->toBe([1, 4, 5, 6, 7, 8, 9, 10]);
    }
});
