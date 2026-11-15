<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use Closure;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Support\Facades\Date;
use Workbench\App\Models\Product;

final class DateFixtures
{
    public static function ids(
        string $input,
        Closure|DateTimeZone|string $timezone = 'America/Managua',
        null|array $fields = null,
    ): array {
        return Product::query()
            ->where('tenant_id', 1)
            ->tap(self::search($input, $timezone, $fields))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public static function search(
        string $input,
        Closure|DateTimeZone|string $timezone = 'America/Managua',
        null|array $fields = null,
    ): Search {
        return Search::make()->fields($fields ?? [
            Field::date('created')->column('created_at')->allowPartialDates()->nullable(),
            Field::date('available')
                ->column('available_on')
                ->withoutTime()
                ->allowPartialDates()
                ->nullable(),
        ])->timezone($timezone)->query($input);
    }

    public static function seed(): void
    {
        config()->set('app.timezone', 'UTC');

        foreach ([
            ['2025-01-15 05:59:59', '2025-01-14'],
            ['2025-01-15 06:00:00', '2025-01-15'],
            ['2025-01-16 05:59:59', '2025-01-15'],
            ['2025-01-16 06:00:00', '2025-01-16'],
            [null, null],
        ] as $index => [$created, $available]) {
            Product::query()->create([
                'id' => $index + 1,
                'name' => 'Product '.($index + 1),
                'status' => 'open',
                'created_at' => $created,
                'available_on' => $available,
            ]);
        }

        Product::query()->create([
            'id' => 6,
            'tenant_id' => 2,
            'name' => 'Other tenant',
            'status' => 'open',
            'created_at' => '2025-01-15 06:00:00',
            'available_on' => '2025-01-15',
        ]);

        test()->beforeApplicationDestroyed(static function (): void {
            Date::setTestNow();
        });
    }
}
