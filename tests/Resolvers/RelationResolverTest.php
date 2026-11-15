<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\FullTextFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\RelationFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\Status;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Line;
use Workbench\App\Models\Member;
use Workbench\App\Models\Product;
use Workbench\App\Models\Tag;

it('keeps typed values storage conversions aliases defaults and core restrictions on relations', function (): void {
    RelationFixtures::seed();

    $uuid = '550e8400-e29b-41d4-a716-446655440001';
    $ulid = '01JGFJJZ00AG2HXTQ2RZ7Y6D8M';

    Product::query()->findOrFail(1)->lines()->create([
        'status' => 'open',
        'price_cents' => 1_050,
        'quantity' => 10,
        'uuid' => $uuid,
        'ulid' => $ulid,
        'active' => true,
    ]);
    Product::query()->findOrFail(2)->lines()->create(['status' => 'closed', 'quantity' => 20]);

    $fields = [
        Field::in('state', ['open', 'closed'])
            ->relation('lines', 'status')
            ->alias('active', 'open'),
        Field::enum('status', Status::class)->relation('lines'),
        Field::uuid('uuid')->relation('lines'),
        Field::ulid('ulid')->relation('lines'),
        Field::boolean('enabled')->relation('lines', 'active'),
        Field::decimal('price', 0, 2)->relation('lines', 'price_cents')->scale(100),
        Field::in('level', ['low', 'high'])
            ->relation('lines', 'quantity')
            ->map(['low' => 10, 'high' => 20]),
    ];

    foreach ([
        'state:OPEN',
        'state:active',
        'status:OPEN',
        'uuid:'.$uuid,
        'ulid:'.$ulid,
        'enabled:true',
        'price:10.50',
        'level:low',
    ] as $input) {
        expect(RelationFixtures::ids($input, $fields))->toBe([1])
            ->and(RelationFixtures::ids('NOT '.$input, $fields))->toBe(range(2, 7));
    }

    expect(RelationFixtures::ids('price:10.50,20', $fields))->toBe([1])
        ->and(RelationFixtures::ids('price:10..11', $fields))->toBe([1])
        ->and(
            RelationFixtures::ids(
                '',
                [
                    Field::in('state', ['open', 'closed'])
                        ->relation('lines', 'status')
                        ->default('open'),
                ],
            ),
        )->toBe(
            [1],
        )
        ->and(RelationFixtures::ids('10.50', $fields, ['price']))->toBe([1])
        ->and(RelationFixtures::ids($uuid, $fields, ['uuid']))->toBe([1])
        ->and(RelationFixtures::ids('-10.50', $fields, ['price']))->toBe(range(2, 7));

    $field = Field::integer('quantity')
        ->relation('lines')
        ->min(0)
        ->max(20)
        ->withoutLists()
        ->withoutRanges()
        ->withoutExists()
        ->operators('=');

    foreach ([
        'quantity:bad',
        'quantity:21',
        'quantity:>10',
        'quantity:10,20',
        'quantity:10..20',
        'quantity:*',
    ] as $input) {
        expect(fn (): array => RelationFixtures::ids($input, [$field]))->toThrow(
            ValidationException::class,
        );
    }
});

it('builds explicit morph subqueries without querying stored type names', function (): void {
    RelationFixtures::seed();

    $connection = Product::query()->getConnection();

    $connection->enableQueryLog();
    $connection->flushQueryLog();

    try {
        $query = Product::query()->tap(RelationFixtures::search('subject:php', [
            Field::string('subject')->relation('subject', 'name', [Customer::class, Member::class]),
        ]));

        expect($query->toSql())->toContain('exists')->and($connection->getQueryLog())->toBeEmpty();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
});

it('keeps each relation operator and its complements inside the related query', function (
    string $value,
    array $expected,
): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    foreach ([false, true] as $nullable) {
        $field = Field::integer('quantity')->relation('lines');

        if ($nullable) {
            $field->nullable();
        }

        $complement = array_values(array_diff(range(1, 7), $expected));

        expect(RelationFixtures::ids('quantity:'.$value, [$field]))->toBe($expected)
            ->and(RelationFixtures::ids('-quantity:'.$value, [$field]))->toBe($complement)
            ->and(RelationFixtures::ids('NOT quantity:'.$value, [$field]))->toBe($complement);
    }
})->with([
    ['10', [1]],
    ['!=10', [2, 3, 4, 5, 6, 7]],
    ['>10', [2, 3]],
    ['>=10', [1, 2, 3]],
    ['<10', [2]],
    ['<=10', [1, 2]],
    ['10,20', [1, 3]],
    ['!=10,20', [2, 4, 5, 6, 7]],
    ['[10 TO 20]', [1, 3]],
    ['10..20', [1, 3]],
    ['*..10', [1, 2]],
    ['20..*', [2, 3]],
    ['*..*', [1, 2, 3]],
    ['*', [1, 2, 3]],
]);

it('keeps global scopes and selectively includes soft-deleted related models along the path', function (): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    $field = Field::string('tag')->relation('lines', 'name');

    expect(RelationFixtures::ids('tag:php', [$field]))->toBe([1])
        ->and(RelationFixtures::ids('tag:php', [$field->withTrashedRelated()]))->toBe([1, 7])
        ->and(RelationFixtures::ids('-tag:php', [$field]))->toBe([2, 3, 4, 5, 6]);

    $customer = Customer::query()->create(['name' => 'PHP', 'deleted_at' => '2025-01-01']);
    $member = Member::query()->create(['name' => 'PHP']);

    Product::query()->findOrFail(1)->update(['customer_id' => $customer->getKey()]);
    Product::query()->findOrFail(1)->subject()->associate($customer)->save();
    Product::query()->findOrFail(2)->subject()->associate($member)->save();

    $line = Line::withTrashed()->where('product_id', 7)->firstOrFail();

    $line->update(['customer_id' => $customer->getKey()]);
    $line->shipments()->create(['name' => 'PHP', 'deleted_at' => '2025-01-01']);

    foreach (['lines.customer', 'shipments', 'shipment'] as $path) {
        $field = Field::string('value')->relation($path, 'name');

        expect(RelationFixtures::ids('value:php', [$field]))->toBeEmpty()
            ->and(RelationFixtures::ids('value:php', [$field->withTrashedRelated()]))->toBe([7]);
    }

    $field = Field::string('value')->relation('subject', 'name', [Customer::class, Member::class]);

    expect(RelationFixtures::ids('value:php', [$field]))->toBe([2])
        ->and(RelationFixtures::ids('-value:php', [$field]))->toBe([1, 3, 4, 5, 6, 7])
        ->and(RelationFixtures::ids('value:php', [$field->withTrashedRelated()]))->toBe([1, 2]);
});

it('keeps mixed text lists in one subquery and repeated clauses independent', function (): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    Product::query()->findOrFail(1)->lines()->create(['name' => 'Laravel']);

    foreach (['contains', 'prefix'] as $mode) {
        $field = Field::string('tag')->relation('lines', 'name')->{$mode}();
        $search = RelationFixtures::search('tag:ph,"Laravel"', [$field]);
        $query = Product::query()->where('tenant_id', 1)->tap($search);

        expect($query->orderBy('id')->pluck('id')->all())->toBe([1, 2])
            ->and(mb_substr_count(mb_strtolower($query->toSql()), 'exists ('))->toBe(1)
            ->and(RelationFixtures::ids('tag:!=ph,"Laravel"', [$field]))->toBe([3, 4, 5, 6, 7])
            ->and(RelationFixtures::ids('NOT tag:ph,"Laravel"', [$field]))->toBe([3, 4, 5, 6, 7]);
    }

    $field = Field::string('tag')->relation('lines', 'name');

    expect(RelationFixtures::ids('tag:php tag:laravel', [$field]))->toBe([1])
        ->and(RelationFixtures::ids('tag:php AND NOT tag:laravel', [$field]))->toBeEmpty()
        ->and(RelationFixtures::ids('NOT (tag:php OR tag:laravel)', [$field]))->toBe([
            3,
            4,
            5,
            6,
            7,
        ]);

    $field = Field::integer('quantity')->relation('lines');

    expect(RelationFixtures::ids('quantity:10..20', [$field]))->toBe([1, 3])
        ->and(RelationFixtures::ids('quantity:>=10 quantity:<=20', [$field]))->toBe([1, 2, 3]);
});

it('matches wildcard lists on relations as any of their items', function (): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    $field = Field::string('tag')->relation('lines', 'name');

    expect(RelationFixtures::ids('tag:ph*,ru*', [$field]))->toBe([1, 2])
        ->and(RelationFixtures::ids('tag:!=ph*,ru*', [$field]))->toBe([3, 4, 5, 6, 7])
        ->and(RelationFixtures::ids('NOT tag:ph*,ru*', [$field]))->toBe([3, 4, 5, 6, 7])
        ->and(RelationFixtures::ids('tag:ph*,"Laravel"', [$field]))->toBe([1, 2])
        ->and(RelationFixtures::ids('tag:!=ph*,"Laravel"', [$field]))->toBe([3, 4, 5, 6, 7]);
});

it('preserves empty word alternatives when a relation or parent is absent', function (): void {
    FullTextFixtures::seed();

    Product::query()->whereKey(5)->update(['parent_id' => 4]);
    Product::query()->whereKey(6)->update(['parent_id' => 1]);

    $search = Search::make()->fields([
        Field::string('parent')->words()->relation('parent', 'name')->likeFallback(),
    ]);

    foreach ([
        'parent:"!!!",coffee' => [5, 6],
        'NOT parent:"!!!",coffee' => [1, 2, 3, 4],
        'parent:"!!!"' => [],
        'NOT parent:"!!!"' => [1, 2, 3, 4, 5, 6],
    ] as $input => $ids) {
        expect(
            Product::query()->tap($search->query($input))->orderBy('id')->pluck('id')->all(),
        )->toBe(
            $ids,
        );
    }
})->group('full-text');

it('preserves full-text relation lists terms complements and self-relation writes', function (): void {
    FullTextFixtures::seed();

    Product::query()->whereKey(5)->update(['parent_id' => 4]);
    Product::query()->whereKey(6)->update(['parent_id' => 1]);

    $search = Search::make()->fields([
        Field::string('parent')->words()->relation('parent', 'name')->likeFallback(),
    ])->terms(['parent']);

    foreach ([
        'parent:coffee' => [5, 6],
        'parent:"coffee ceramic",espresso' => [6],
        'NOT parent:"coffee ceramic",espresso' => [1, 2, 3, 4, 5],
        '"coffee ceramic"' => [6],
        '-"coffee ceramic"' => [1, 2, 3, 4, 5],
    ] as $input => $ids) {
        expect(
            Product::query()->tap($search->query($input))->orderBy('id')->pluck('id')->all(),
        )->toBe(
            $ids,
        );
    }

    expect(
        Product::query()->tap($search->query('parent:coffee'))->update(['status' => 'closed']),
    )->toBe(
        2,
    )
        ->and(Product::query()->where('status', 'closed')->orderBy('id')->pluck('id')->all())->toBe(
            [5, 6],
        )
        ->and(
            Product::query()
                ->tap($search->query('NOT parent:coffee'))
                ->update(['status' => 'pending']),
        )->toBe(
            4,
        )
        ->and(Product::query()->tap($search->query('parent:coffee'))->delete())->toBe(2);
})->group('full-text');

it('reuses text matching escaping and multi-column presence on relation fields', function (): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    $fields = [
        Field::string('tag')->relation('lines', 'name')->allowLeadingWildcards(),
        Field::string('person')->relation('lines', ['name', 'alternate_name']),
    ];

    foreach ([
        ['tag:php', [1]],
        ['tag:ph*', [1]],
        ['tag:*hp', [1]],
        ['tag:!=ph*', [2, 3, 4, 5, 6, 7]],
        ['tag:php,rails', [1, 3]],
        ['tag:<rails', [1, 2]],
        ['tag:>=rails', [2, 3]],
        ['tag:[laravel TO rails]', [1, 2, 3]],
        ['person:php', [1, 3]],
        ['person:!=php', [2, 4, 5, 6, 7]],
        ['person:php,laravel', [1, 2, 3]],
        ['person:!=php,laravel', [4, 5, 6, 7]],
        ['person:*', [1, 2, 3]],
        ['-person:*', [4, 5, 6, 7]],
    ] as [$input, $expected]) {
        expect(RelationFixtures::ids($input, $fields))->toBe($expected);
    }

    foreach (['a%b', 'a_b', 'a!b', 'a\\b'] as $literal) {
        Line::query()->where('product_id', 1)->update(['name' => $literal.' suffix']);

        $field = Field::string('tag')->relation('lines', 'name')->contains();

        expect(RelationFixtures::ids('tag:"'.$literal.'"*', [$field]))->toBe([1])
            ->and(RelationFixtures::ids('-tag:"'.$literal.'"*', [$field]))->toBe([2, 3, 4, 5, 6, 7])
            ->and(RelationFixtures::ids('tag:'.$literal, [$field]))->toBe([1]);
    }
});

it('supports belongsTo hasOne hasMany through and nested paths', function (string $path): void {
    RelationFixtures::seed();

    $customer = Customer::query()->create(['name' => 'PHP']);
    $member = Member::query()->create(['name' => 'PHP']);
    $product = Product::query()->findOrFail(1);

    $product->update(['customer_id' => $customer->getKey()]);

    $line = $product->lines()->create([
        'name' => 'PHP',
        'customer_id' => $customer->getKey(),
        'member_id' => $member->getKey(),
    ]);

    $line->shipments()->create(['name' => 'PHP']);

    $field = Field::string('value')->relation($path, 'name');
    $expected = $path === 'lines.product' ? [] : [1];

    expect(RelationFixtures::ids('value:php', [$field]))->toBe($expected)
        ->and(RelationFixtures::ids('value:!=php', [$field]))->toBe(
            array_values(array_diff(range(1, 7), $expected)),
        );

    if ($path === 'lines.product') {
        expect(RelationFixtures::ids('value:"Product 1"', [$field]))->toBe([1]);
    }
})->with([
    'customer',
    'line',
    'lines',
    'shipment',
    'shipments',
    'lines.member',
    'customer.lines.member',
    'lines.product',
]);

it('supports explicit morph types before and after other relation segments', function (
    string $path,
): void {
    RelationFixtures::seed();

    $customer = Customer::query()->create(['name' => 'PHP']);
    $member = Member::query()->create(['name' => 'Laravel']);

    foreach ([1 => $customer, 2 => $member] as $id => $subject) {
        Product::query()->findOrFail($id)->subject()->associate($subject)->save();

        $subject->lines()->create(['product_id' => $id, 'name' => $subject->name]);
    }

    Product::query()
        ->findOrFail(3)
        ->update(['subject_type' => Customer::class, 'subject_id' => 999]);
    Product::query()
        ->findOrFail(4)
        ->subject()
        ->associate(Tag::query()->create(['name' => 'PHP']))
        ->save();
    Product::query()
        ->findOrFail(5)
        ->update(['subject_type' => null, 'subject_id' => $customer->getKey()]);

    Product::query()
        ->findOrFail(6)
        ->subject()
        ->associate(Customer::query()->create(['name' => null]))
        ->save();

    $field = Field::string('value')->relation($path, 'name', [Customer::class, Member::class]);
    $positive = [1];

    expect(RelationFixtures::ids('value:php', [$field]))->toBe($positive)
        ->and(RelationFixtures::ids('-value:php', [$field]))->toBe(
            array_values(array_diff(range(1, 7), $positive)),
        )
        ->and(RelationFixtures::ids('value:php,laravel', [$field]))->toBe([1, 2])
        ->and(RelationFixtures::ids('value:!=php,laravel', [$field]))->toBe([3, 4, 5, 6, 7])
        ->and(RelationFixtures::ids('value:*', [$field]))->toBe([1, 2])
        ->and(RelationFixtures::ids('-value:*', [$field]))->toBe([3, 4, 5, 6, 7]);
})->with(['subject', 'subject.lines', 'lines.product.subject']);

it('supports morphOne morphMany morphToMany and morphedByMany', function (string $path): void {
    RelationFixtures::seed();

    $product = Product::query()->findOrFail(1);

    $product->notes()->create(['name' => 'PHP']);

    $tag = Tag::query()->create(['name' => 'PHP']);

    $product->polymorphicTags()->attach($tag);

    $field = Field::string('value')->relation($path, 'name');

    expect(RelationFixtures::ids('value:php', [$field]))->toBe([1])
        ->and(RelationFixtures::ids('-value:php', [$field]))->toBe(range(2, 7));

    $field = Field::string('product')->relation('products', 'name');

    expect(
        Tag::query()
            ->tap(RelationFixtures::search('product:"Product 1"', [$field]))
            ->pluck('id')
            ->all(),
    )->toBe([$tag->getKey()]);
})->with(['note', 'notes', 'polymorphicTags']);

it('supports pivot columns and nesting after belongsToMany', function (): void {
    RelationFixtures::seed();

    $tag = Tag::query()->create(['name' => 'PHP']);
    $product = Product::query()->findOrFail(1);

    $product->tags()->attach($tag, ['role' => 'Admin']);
    $product->polymorphicTags()->attach($tag, ['role' => 'Editor']);

    $fields = [
        Field::string('tag')->relation('tags', 'name'),
        Field::string('role')->relation('tags', 'product_tag.role'),
        Field::string('morph_role')->relation('polymorphicTags', 'taggables.role'),
        Field::string('nested')->relation('tags.products', 'name'),
    ];

    expect(
        RelationFixtures::ids('tag:php role:admin morph_role:editor nested:"Product 1"', $fields),
    )->toBe(
        [
            1,
        ],
    )
        ->and(RelationFixtures::ids('role:!=admin', $fields))->toBe(range(2, 7))
        ->and(RelationFixtures::ids('morph_role:!=editor', $fields))->toBe(range(2, 7));

    $query = Product::query()->tap(RelationFixtures::search('tag:php', $fields));

    expect($query->getQuery()->joins)->toBeNull();
});

it('uses configured morph classes with Laravel morph maps', function (): void {
    RelationFixtures::seed();

    Relation::morphMap(['customer' => Customer::class, 'member' => Member::class]);

    try {
        $customer = Customer::query()->create(['name' => 'PHP']);

        Product::query()->findOrFail(1)->subject()->associate($customer)->save();

        $field = Field::string('subject')
            ->relation('subject', 'name', [Customer::class, Member::class]);

        expect(RelationFixtures::ids('subject:php', [$field]))->toBe([1])
            ->and(RelationFixtures::ids('subject:!=php', [$field]))->toBe(range(2, 7));
    } finally {
        Relation::morphMap([], false);
    }
});

it('uses relation fields as typed term targets while preserving the root WHERE group', function (): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    $fields = [
        Field::string('name'),
        Field::string('tag')->relation('lines', 'name'),
        Field::integer('quantity')->relation('lines'),
    ];
    $terms = ['name', 'tag', 'quantity'];

    expect(RelationFixtures::ids('php', $fields, $terms))->toBe([1])
        ->and(RelationFixtures::ids('-php', $fields, $terms))->toBe(range(2, 7))
        ->and(RelationFixtures::ids('ph*', $fields, $terms))->toBe([1])
        ->and(RelationFixtures::ids('php OR 20', $fields, $terms))->toBe([1, 3])
        ->and(RelationFixtures::ids('php 10', $fields, $terms))->toBe([1])
        ->and(RelationFixtures::ids('coffee', $fields, ['quantity']))->toBeEmpty()
        ->and(RelationFixtures::ids('-coffee', $fields, ['quantity']))->toBe(range(1, 7))
        ->and(RelationFixtures::ids('10*', $fields, ['quantity']))->toBeEmpty();
});

it('uses the internal name by default and lets column setters replace related targets', function (): void {
    RelationFixtures::seed();

    RelationFixtures::rows();

    expect(
        RelationFixtures::ids('label:php', [Field::string('label', 'name')->relation('lines')]),
    )->toBe(
        [1],
    )
        ->and(
            RelationFixtures::ids(
                'label:php',
                [Field::string('label', 'name')->column('alternate_name')->relation('lines')],
            ),
        )->toBe(
            [1],
        )
        ->and(
            RelationFixtures::ids(
                'label:php',
                [Field::string('label')->relation('lines', 'name')->column('alternate_name')],
            ),
        )->toBe(
            [3],
        )
        ->and(
            RelationFixtures::ids(
                'label:php',
                [
                    Field::string('label')
                        ->relation('lines', 'name')
                        ->columns(['name', 'alternate_name']),
                ],
            ),
        )->toBe(
            [1, 3],
        );
});
