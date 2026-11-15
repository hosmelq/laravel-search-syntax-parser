<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\AggregateFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\DateFixtures;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\RelationFixtures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Product;

it('preserves date defaults aliases and core restrictions', function (): void {
    DateFixtures::seed();

    Date::setTestNow(CarbonImmutable::parse('2025-01-16T02:00:00Z'));

    $fields = [
        Field::date('created')
            ->column('created_at')
            ->default('today')
            ->alias('@day', '2025-01-16')
            ->nullable(),
    ];

    expect(DateFixtures::ids('', fields: $fields))->toBe([2, 3])
        ->and(DateFixtures::ids('created:@day', fields: $fields))->toBe([4])
        ->and(
            fn (): array
                => DateFixtures::ids(
                    'created:2025-01-14,2025-01-15',
                    fields: [Field::date('created')->withoutLists()],
                ),
        )
        ->toThrow(ValidationException::class)
        ->and(
            fn (): array
                => DateFixtures::ids(
                    'created:>2025-01-15',
                    fields: [Field::date('created')->operators('=')],
                ),
        )
        ->toThrow(ValidationException::class);
});

it('rejects times and instants on calendar DATE fields with positioned errors', function (
    string $value,
): void {
    DateFixtures::seed();

    try {
        DateFixtures::ids('available:'.$value);

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['q'][0])->toContain(
            'Invalid value for field "available".',
            'At position',
        );
    }
})->with(['2025-01-15T10', '2025-01-15T10:30Z', 'now', 'now-7d', '-7d']);

it('reports relative dates that leave the supported years at request time as validation errors', function (
    string $input,
    Closure $now,
    string $timezone,
): void {
    DateFixtures::seed();

    Date::setTestNow($now());

    try {
        DateFixtures::ids($input, $timezone);

        test()->fail('Expected a validation error.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['q'][0])
            ->toContain('"created"')
            ->toContain('position 0');
    }
})->with([
    'a local new year ahead of UTC' => [
        'created:today+'.(9_999 - (int) gmdate('Y')).'y',
        static fn (): CarbonImmutable
            => CarbonImmutable::create((int) gmdate('Y'), 12, 31, 12, 0, 0, 'UTC'),
        'Pacific/Kiritimati',
    ],
    'an application clock a year ahead' => [
        'created:>now+'.(9_999 - (int) gmdate('Y')).'y',
        static fn (): CarbonImmutable => CarbonImmutable::now('UTC')->addYear(),
        'UTC',
    ],
    'an application clock a year behind' => [
        'created:today-'.((int) gmdate('Y') - 1).'y',
        static fn (): CarbonImmutable => CarbonImmutable::now('UTC')->subYear(),
        'UTC',
    ],
]);

it('supports years and months while keeping partial dates opt in', function (): void {
    DateFixtures::seed();

    Product::query()->whereKey(1)->update(['created_at' => '2025-01-01 05:59:59']);
    Product::query()->whereKey(4)->update(['created_at' => '2025-02-01 06:00:00']);

    expect(DateFixtures::ids('created:2025-01'))->toBe([2, 3])
        ->and(DateFixtures::ids('created:2025'))->toBe([2, 3, 4])
        ->and(DateFixtures::ids('created:2024-12..2025-01'))->toBe([1, 2, 3])
        ->and(fn (): array => DateFixtures::ids('created:2025', fields: [Field::date('created')]))
        ->toThrow(ValidationException::class);
});

it('uses related date precision time zones intervals and missing values for min and max', function (
    string $function,
): void {
    AggregateFixtures::seed();

    foreach ([false, true] as $constrained) {
        foreach ([false, true] as $replaceEmpty) {
            $field = Field::date('value')->{$function.'Of'}(
                'lines',
                'created_at',
                $constrained ? fn (Builder $query) => $query->where('quantity', '>=', 20) : null,
            )->allowPartialDates();

            if ($replaceEmpty) {
                $field->whenEmpty('2025-01-15 06:00:00.000000');
            }

            $onDay = $constrained ? [1, 7] : ($function === 'min' ? [2, 7] : [1, 2, 7]);
            $beforeDay = ! $constrained && $function === 'min' ? [1] : [];
            $afterDay = $constrained ? [] : [6];
            $present = $constrained ? [1, 7] : [1, 2, 6, 7];

            if ($replaceEmpty) {
                $onDay = array_values(
                    array_unique([...$onDay, ...array_diff(range(1, 7), $present)]),
                );

                sort($onDay);

                $present = range(1, 7);
            }

            foreach ([
                ['2025-01-15', $onDay],
                ['>2025-01-15', $afterDay],
                ['>=2025-01-15', array_values(array_diff($present, $beforeDay))],
                ['<2025-01-15', $beforeDay],
                ['<=2025-01-15', array_values(array_diff($present, $afterDay))],
                ['2025-01-14,2025-01-15', array_values(array_diff($present, $afterDay))],
                ['2025-01-14..2025-01-15', array_values(array_diff($present, $afterDay))],
                ['*..2025-01-15', array_values(array_diff($present, $afterDay))],
                ['2025-01-15..*', array_values(array_diff($present, $beforeDay))],
                ['*..*', $present],
                ['*', $present],
            ] as [$value, $expected]) {
                expect(AggregateFixtures::ids('value:'.$value, $field))->toBe($expected)
                    ->and(AggregateFixtures::ids('NOT value:'.$value, $field))->toBe(
                        array_values(array_diff(range(1, 7), $expected)),
                    );
            }
        }
    }

    $field = Field::date('value')->{$function.'Of'}('lines', 'available_on')->withoutTime();

    expect(AggregateFixtures::ids('value:2025-01-15', $field))->toBe(
        $function === 'min' ? [2] : [1, 2],
    )
        ->and(
            fn (): array => AggregateFixtures::ids('value:2025-01-15T00:00:00Z', $field),
        )->toThrow(
            ValidationException::class,
        );
})->with(['min', 'max']);

it('applies calendar and elapsed offsets to real stored rows', function (
    string $clock,
    string $value,
    array $ids,
): void {
    DateFixtures::seed();

    Date::setTestNow(new DateTimeImmutable($clock));

    expect(DateFixtures::ids('created:'.$value))->toBe($ids);
})->with([
    ['2025-01-14T12:00:00Z', 'today+1d', [2, 3]],
    ['2025-01-22T12:00:00Z', 'today-1w', [2, 3]],
    ['2025-01-08T12:00:00Z', 'today+1w', [2, 3]],
    ['2025-02-15T12:00:00Z', 'today-1M', [2, 3]],
    ['2024-12-15T12:00:00Z', 'today+1M', [2, 3]],
    ['2026-01-15T12:00:00Z', 'today-1y', [2, 3]],
    ['2024-01-15T12:00:00Z', 'today+1y', [2, 3]],
    ['2025-01-15T08:00:00.5Z', '>now-2h', [3, 4]],
    ['2025-01-15T04:00:00.5Z', '>now+2h', [3, 4]],
    ['2025-01-15T06:30:00.5Z', '>now-30m', [3, 4]],
    ['2025-01-15T05:30:00.5Z', '>now+30m', [3, 4]],
]);

it('clips out-of-range storage bounds before they reach any driver', function (
    string $input,
    string $zone,
    array $ids,
): void {
    DateFixtures::seed();

    $query = Product::query()->where('tenant_id', 1)->tap(DateFixtures::search($input, $zone));

    expect($query->orderBy('id')->pluck('id')->all())->toBe($ids);

    foreach ($query->getBindings() as $binding) {
        if (is_string($binding)) {
            foreach (['0000-', '10000-', '-0001-'] as $prefix) {
                expect($binding)->not->toStartWith($prefix);
            }
        }
    }
})->with([
    ['created:<=9999-12-31', 'America/Managua', [1, 2, 3, 4]],
    ['NOT created:<=9999-12-31', 'America/Managua', [5]],
    ['created:>9999-12-31', 'America/Managua', []],
    ['NOT created:>9999-12-31', 'America/Managua', [1, 2, 3, 4, 5]],
    ['created:<0001-01-01', 'Asia/Tokyo', []],
    ['NOT created:<0001-01-01', 'Asia/Tokyo', [1, 2, 3, 4, 5]],
    ['created:>=0001-01-01', 'Asia/Tokyo', [1, 2, 3, 4]],
    ['NOT created:>=0001-01-01', 'Asia/Tokyo', [5]],
    ['created:9999-12-31', 'America/Managua', []],
    ['created:0001-01-01', 'Asia/Tokyo', []],
    ['available:<=9999-12-31', 'America/Managua', [1, 2, 3, 4]],
    ['available:>9999-12-31', 'America/Managua', []],
    ['available:<0001-01-01', 'Asia/Tokyo', []],
]);

it('combines date targets as alternatives and requires all complements to miss', function (): void {
    DateFixtures::seed();

    Product::query()->whereKey(1)->update(['quantity' => 2]);
    Product::query()->whereKey(2)->update(['quantity' => 4]);
    Product::query()->whereKey(4)->update(['quantity' => 5]);

    $fields = [
        Field::date('either')
            ->columns(['products.created_at', 'alternatives.created_at'])
            ->nullable(),
    ];
    $ids = fn (string $input): array => Product::query()
        ->leftJoin('products as alternatives', 'alternatives.id', '=', 'products.quantity')
        ->where('products.tenant_id', 1)
        ->tap(DateFixtures::search($input, fields: $fields))
        ->orderBy('products.id')->pluck('products.id')->all();

    expect($ids('either:2025-01-15'))->toBe([1, 2, 3])
        ->and($ids('either:!=2025-01-15'))->toBe([4, 5])
        ->and($ids('either:2025-01-14,2025-01-16'))->toBe([1, 2, 4])
        ->and($ids('either:!=2025-01-14,2025-01-16'))->toBe([3, 5])
        ->and($ids('either:*'))->toBe([1, 2, 3, 4])
        ->and($ids('-either:*'))->toBe([5]);

    $expression = [
        Field::date('created')
            ->expression(fn (string $driver): Expression => new Expression('created_at'))
            ->nullable(),
    ];

    expect(DateFixtures::ids('created:2025-01-15', fields: $expression))->toBe([2, 3])
        ->and(DateFixtures::ids('-created:2025-01-15', fields: $expression))->toBe([1, 4, 5]);
});

it('compiles date operators and complements without losing nullable rows', function (
    string $input,
    array $ids,
): void {
    DateFixtures::seed();

    expect(DateFixtures::ids($input))->toBe($ids);
})->with([
    ['created:2025-01-15', [2, 3]],
    ['created:!=2025-01-15', [1, 4, 5]],
    ['NOT created:2025-01-15', [1, 4, 5]],
    ['created:>2025-01-15', [4]],
    ['created:>=2025-01-15', [2, 3, 4]],
    ['created:<2025-01-15', [1]],
    ['created:<=2025-01-15', [1, 2, 3]],
    ['NOT created:>2025-01-15', [1, 2, 3, 5]],
    ['NOT created:>=2025-01-15', [1, 5]],
    ['NOT created:<2025-01-15', [2, 3, 4, 5]],
    ['NOT created:<=2025-01-15', [4, 5]],
    ['NOT created:!=2025-01-15', [2, 3]],
    ['created:2025-01-14,2025-01-16', [1, 4]],
    ['created:!=2025-01-14,2025-01-16', [2, 3, 5]],
    ['created:[2025-01-14 TO 2025-01-15]', [1, 2, 3]],
    ['created:2025-01-14..2025-01-15', [1, 2, 3]],
    ['-created:2025-01-14..2025-01-15', [4, 5]],
    ['created:*..2025-01-15', [1, 2, 3]],
    ['-created:*..2025-01-15', [4, 5]],
    ['created:2025-01-15..*', [2, 3, 4]],
    ['-created:2025-01-15..*', [1, 5]],
    ['created:*..*', [1, 2, 3, 4]],
    ['-created:*..*', [5]],
    ['created:*', [1, 2, 3, 4]],
    ['-created:*', [5]],
    ['created:2025-01-16..2025-01-14', []],
    ['-created:2025-01-16..2025-01-14', [1, 2, 3, 4, 5]],
]);

it('handles skipped local times as empty intervals', function (): void {
    DateFixtures::seed();

    Product::query()->whereKey(1)->update(['created_at' => '2025-03-09 07:00:00']);

    expect(DateFixtures::ids('created:2025-03-09T02:30', 'America/New_York'))->toBeEmpty()
        ->and(DateFixtures::ids('-created:2025-03-09T02:30', 'America/New_York'))->toBe([
            1,
            2,
            3,
            4,
            5,
        ]);
});

it('honors explicit offsets and the application storage zone', function (): void {
    DateFixtures::seed();

    expect(DateFixtures::ids('created:2025-01-15T08:00+02:00'))->toBe([2])
        ->and(DateFixtures::ids('created:2025-01-15T06:00Z', 'Asia/Tokyo'))->toBe([2]);

    config()->set('app.timezone', 'America/New_York');

    Product::query()->whereKey(2)->update(['created_at' => '2025-01-15 01:00:00']);

    expect(DateFixtures::ids('created:2025-01-15T06:00Z'))->toBe([2]);
});

it('keeps every date operator and complement correct on a non-nullable field', function (
    string $input,
    array $ids,
): void {
    DateFixtures::seed();

    Product::query()->whereKey(5)->delete();

    expect(DateFixtures::ids($input, fields: [Field::date('created')->column('created_at')]))->toBe(
        $ids,
    );
})->with([
    ['created:2025-01-15', [2, 3]],
    ['created:!=2025-01-15', [1, 4]],
    ['NOT created:2025-01-15', [1, 4]],
    ['created:>2025-01-15', [4]],
    ['created:>=2025-01-15', [2, 3, 4]],
    ['created:<2025-01-15', [1]],
    ['created:<=2025-01-15', [1, 2, 3]],
    ['NOT created:>2025-01-15', [1, 2, 3]],
    ['NOT created:>=2025-01-15', [1]],
    ['NOT created:<2025-01-15', [2, 3, 4]],
    ['NOT created:<=2025-01-15', [4]],
    ['created:2025-01-14,2025-01-16', [1, 4]],
    ['created:!=2025-01-14,2025-01-16', [2, 3]],
    ['created:2025-01-14..2025-01-15', [1, 2, 3]],
    ['-created:2025-01-14..2025-01-15', [4]],
    ['created:*..*', [1, 2, 3, 4]],
    ['-created:*..*', []],
    ['created:*', [1, 2, 3, 4]],
    ['-created:*', []],
]);

it('queries calendar DATE targets including SQLite date-cast storage', function (
    string $input,
    array $ids,
): void {
    DateFixtures::seed();

    expect(DateFixtures::ids($input, 'Pacific/Auckland'))->toBe($ids);
})->with([
    ['available:2025-01-15', [2, 3]],
    ['available:!=2025-01-15', [1, 4, 5]],
    ['available:>2025-01-15', [4]],
    ['available:>=2025-01-15', [2, 3, 4]],
    ['available:<2025-01-15', [1]],
    ['available:<=2025-01-15', [1, 2, 3]],
    ['NOT available:>2025-01-15', [1, 2, 3, 5]],
    ['NOT available:>=2025-01-15', [1, 5]],
    ['NOT available:<2025-01-15', [2, 3, 4, 5]],
    ['NOT available:<=2025-01-15', [4, 5]],
    ['available:2025-01-14,2025-01-16', [1, 4]],
    ['available:!=2025-01-14,2025-01-16', [2, 3, 5]],
    ['available:[2025-01-14 TO 2025-01-15]', [1, 2, 3]],
    ['available:2025-01-14..2025-01-15', [1, 2, 3]],
    ['-available:2025-01-14..2025-01-15', [4, 5]],
    ['available:2025-01', [1, 2, 3, 4]],
    ['available:*', [1, 2, 3, 4]],
    ['-available:*', [5]],
]);

it('resolves calendar day anchors in the user zone without converting stored dates', function (): void {
    DateFixtures::seed();

    Date::setTestNow(CarbonImmutable::parse('2025-01-16T02:00:00Z'));

    expect(DateFixtures::ids('available:today'))->toBe([2, 3])
        ->and(DateFixtures::ids('available:yesterday'))->toBe([1])
        ->and(DateFixtures::ids('available:tomorrow'))->toBe([4])
        ->and(DateFixtures::ids('available:today-1d'))->toBe([1]);
});

it('resolves related date formats and time zones with the date resolver', function (): void {
    RelationFixtures::seed();

    foreach ([
        [1, '2025-01-15 05:59:59.999999', '2025-01-14'],
        [2, '2025-01-15 06:00:00.000001', '2025-01-15'],
        [3, '2025-01-16 05:59:59.999999', '2025-01-15'],
        [4, '2025-01-16 06:00:00.000000', '2025-01-16'],
        [6, null, null],
    ] as [$id, $created, $available]) {
        Product::query()->findOrFail($id)->lines()->create([
            'created_at' => $created,
            'available_on' => $available,
        ]);
    }

    $fields = [
        Field::date('created')->relation('lines', 'created_at')->allowPartialDates(),
        Field::date('available')->relation('lines', 'available_on')->withoutTime(),
    ];

    foreach ([
        ['created:2025-01-15', [2, 3]],
        ['created:2025-01-15T06:00:00.000001Z', [2]],
        ['created:>2025-01-15', [4]],
        ['created:>=2025-01-15', [2, 3, 4]],
        ['created:<2025-01-15', [1]],
        ['created:<=2025-01-15', [1, 2, 3]],
        ['created:2025-01-14,2025-01-16', [1, 4]],
        ['created:2025-01-14..2025-01-15', [1, 2, 3]],
        ['created:*', [1, 2, 3, 4]],
        ['available:2025-01-15', [2, 3]],
    ] as [$input, $expected]) {
        foreach ([false, true] as $negative) {
            $search = RelationFixtures::search(($negative ? 'NOT ' : '').$input, $fields)->timezone(
                'America/Managua',
            );

            expect(
                Product::query()
                    ->where('tenant_id', 1)
                    ->tap($search)
                    ->orderBy('id')
                    ->pluck('id')
                    ->all(),
            )->toBe($negative ? array_values(array_diff(range(1, 7), $expected)) : $expected);
        }
    }

    Date::setTestNow('2025-01-15 12:00:00');

    try {
        expect(Product::query()->where('tenant_id', 1)->tap(
            RelationFixtures::search('created:today', $fields)->timezone('America/Managua'),
        )->orderBy('id')->pluck('id')->all())->toBe([2, 3]);
    } finally {
        Date::setTestNow();
    }
});

it('resolves relative days and offsets from the Laravel clock in the user zone', function (
    string $value,
    array $ids,
): void {
    DateFixtures::seed();

    Date::setTestNow(CarbonImmutable::parse('2025-01-16T02:00:00.500000Z'));

    expect(DateFixtures::ids('created:'.$value))->toBe($ids);
})->with([
    ['today', [2, 3]],
    ['yesterday', [1]],
    ['tomorrow', [4]],
    ['today-1d', [1]],
    ['yesterday+1d', [2, 3]],
    ['tomorrow-1w', []],
    ['today+1M', []],
    ['today-1y', []],
    ['[today TO tomorrow]', [2, 3, 4]],
    ['today..now', [2]],
]);

it('rounds both bounds up to the stored precision', function (string $input, array $ids): void {
    DateFixtures::seed();

    Product::query()->whereKey(1)->update(['created_at' => '2025-01-15 10:30:00']);
    Product::query()->whereKey(2)->update(['created_at' => '2025-01-15 10:30:01']);

    expect(DateFixtures::ids($input, 'UTC'))->toBe($ids);
})->with([
    ['created:2025-01-15T10:30:00.5', []],
    ['created:!=2025-01-15T10:30:00.5', [1, 2, 3, 4, 5]],
    ['created:>2025-01-15T10:30:00.5', [2, 3, 4]],
    ['created:>=2025-01-15T10:30:00.5', [2, 3, 4]],
    ['created:<2025-01-15T10:30:00.5', [1]],
    ['created:<=2025-01-15T10:30:00.5', [1]],
    ['NOT created:>2025-01-15T10:30:00.5', [1, 5]],
    ['created:2025-01-15T10:30:00.000000', [1]],
]);

it('treats now and bare offsets as instants instead of whole days', function (): void {
    DateFixtures::seed();

    Date::setTestNow(CarbonImmutable::parse('2025-01-22T06:00:00.500000Z'));

    expect(DateFixtures::ids('created:now-7d'))->toBeEmpty()
        ->and(DateFixtures::ids('created:-7d'))->toBeEmpty()
        ->and(DateFixtures::ids('-created:now-7d'))->toBe([1, 2, 3, 4, 5])
        ->and(DateFixtures::ids('created:>now-7d'))->toBe([3, 4])
        ->and(DateFixtures::ids('created:<=now-7d'))->toBe([1, 2])
        ->and(DateFixtures::ids('created:now-168h'))->toBeEmpty()
        ->and(DateFixtures::ids('created:now-10080m'))->toBeEmpty()
        ->and(DateFixtures::ids('created:now'))->toBeEmpty()
        ->and(DateFixtures::ids('-created:now'))->toBe([1, 2, 3, 4, 5]);
});

it('uses actual DST day boundaries and the first instant after a skipped midnight', function (
    string $zone,
    string $day,
    string $start,
    string $end,
): void {
    DateFixtures::seed();

    foreach ([
        $start,
        new DateTimeImmutable($start)->modify('-1 second')->format('Y-m-d H:i:s'),
        new DateTimeImmutable($end)->modify('-1 second')->format('Y-m-d H:i:s'),
        $end,
    ] as $index => $value) {
        Product::query()->whereKey($index + 1)->update(['created_at' => $value]);
    }

    expect(DateFixtures::ids('created:'.$day, $zone))->toBe([1, 3])
        ->and(DateFixtures::ids('-created:'.$day, $zone))->toBe([2, 4, 5]);
})->with([
    ['America/New_York', '2025-03-09', '2025-03-09 05:00:00', '2025-03-10 04:00:00'],
    ['America/New_York', '2025-11-02', '2025-11-02 04:00:00', '2025-11-03 05:00:00'],
    ['America/Santiago', '2024-09-08', '2024-09-08 04:00:00', '2024-09-09 03:00:00'],
    ['Asia/Amman', '2021-10-29', '2021-10-28 21:00:00', '2021-10-29 22:00:00'],
]);

it('uses microsecond and millisecond model formats for date bindings', function (
    string $format,
    string $input,
    array $bindings,
): void {
    DateFixtures::seed();

    $query = Product::query();

    $query->getModel()->setDateFormat($format);
    $query->tap(DateFixtures::search($input, 'UTC'));

    expect($query->getBindings())->toBe($bindings);
})->with([
    [
        'Y-m-d H:i:s.u',
        'created:2025-01-15T10:30:00.123456',
        ['2025-01-15 10:30:00.123456', '2025-01-15 10:30:00.123457'],
    ],
    ['Y-m-d H:i:s.v', 'created:2025-01-15T10:30:00.123456', []],
    ['Y-m-d H:i:s.v', 'created:>2025-01-15T10:30:00.123456', ['2025-01-15 10:30:00.124']],
    ['Y-m-d H:i:s.v', 'created:>=2025-01-15T10:30:00.999999', ['2025-01-15 10:30:01.000']],
]);

it('uses the related timestamp format for exact aggregate instants and their complements', function (
    string $function,
): void {
    AggregateFixtures::seed();

    $field = Field::date('value')->{$function.'Of'}('lines', 'created_at');
    $first = $function === 'min' ? '2025-01-14T06:00:00.000001Z' : '2025-01-15T06:00:00.000001Z';
    $second = '2025-01-15T06:00:00.000002Z';

    expect(AggregateFixtures::ids('value:'.$first, $field))->toBe([1])
        ->and(AggregateFixtures::ids('value:!='.$first, $field))->toBe(range(2, 7))
        ->and(AggregateFixtures::ids('value:'.$first.','.$second, $field))->toBe([1, 2])
        ->and(AggregateFixtures::ids('value:'.$first.'..'.$second, $field))->toBe([1, 2])
        ->and(AggregateFixtures::ids('-value:'.$first.'..'.$second, $field))->toBe(range(3, 7));
})->with(['min', 'max']);
