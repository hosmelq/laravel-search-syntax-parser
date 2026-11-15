<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Exception\ErrorReason;
use HosmelQ\SearchSyntaxParser\Laravel\SearchSyntaxServiceProvider;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;

it('provides translation keys for core reasons and adapter errors', function (): void {
    $keys = [
        ...array_map(
            static fn (ErrorReason $reason): string => $reason->value,
            ErrorReason::cases(),
        ),
        'field.not_allowed_none',
        'position',
        'query.invalid_type',
    ];

    foreach ($keys as $key) {
        expect(Lang::has('search-syntax-parser::errors.'.$key, 'en'))->toBeTrue();
    }
});

it('publishes configuration through the named installer and offers to star the repository', function (): void {
    $published = [];
    $publisher = new ClosureCommand(
        'vendor:publish {--tag=*}',
        function () use (&$published): void {
            $published = $this->option('tag');
        },
    );

    Artisan::registerCommand($publisher);

    test()->artisan('search-syntax-parser:install')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertSuccessful();

    expect($published)->toBe('search-syntax-parser-config');
});

it('registers the config and translation publishing paths with their package namespace', function (): void {
    foreach ([
        'config' => ['config/search-syntax-parser.php', config_path('search-syntax-parser.php')],
        'translations' => ['resources/lang', lang_path('vendor/search-syntax-parser')],
    ] as $tag => [$source, $destination]) {
        $paths = ServiceProvider::pathsToPublish(
            SearchSyntaxServiceProvider::class,
            'search-syntax-parser-'.$tag,
        );

        expect(array_map(realpath(...), array_keys($paths)))->toBe([
            realpath(__DIR__.'/../'.$source),
        ])
            ->and(array_values($paths))->toBe([$destination]);
    }
});
