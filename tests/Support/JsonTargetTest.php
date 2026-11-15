<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Support\JsonTarget;
use Illuminate\Database\MariaDbConnection;

it('retains JSON types with the Laravel MariaDB grammar instead of its scalar-only JSON_VALUE selector', function (): void {
    $connection = new MariaDbConnection(null);
    $target = new JsonTarget("products.meta->o'clock", $connection->getQueryGrammar(), 'mariadb');

    expect($target->raw())->toBe("json_extract(`products`.`meta`, '$.\"o''clock\"')")
        ->and($target->text())->toBe(
            "json_unquote(json_extract(`products`.`meta`, '$.\"o''clock\"'))",
        )
        ->and($target->type())->toBe(
            "lower(json_type(json_extract(`products`.`meta`, '$.\"o''clock\"')))",
        )
        ->and($target->elements()['from'])->toContain('json_table(', 'ordinality for ordinality');
});
