<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Error Key
    |--------------------------------------------------------------------------
    |
    | The attribute used for search validation errors. Form request rules use
    | the attribute being validated instead.
    |
    */

    'error_key' => 'q',

    /*
    |--------------------------------------------------------------------------
    | Maximum Clauses
    |--------------------------------------------------------------------------
    |
    | The maximum number of user-supplied leaf clauses, including ignored
    | clauses. Field defaults do not count toward this positive limit.
    |
    */

    'max_clauses' => (int) env('SEARCH_SYNTAX_PARSER_MAX_CLAUSES', 50),

    /*
    |--------------------------------------------------------------------------
    | Maximum Depth
    |--------------------------------------------------------------------------
    |
    | The maximum nesting depth of groups and negations. This must be a
    | positive integer. Field defaults do not count toward this limit.
    |
    */

    'max_depth' => (int) env('SEARCH_SYNTAX_PARSER_MAX_DEPTH', 5),

    /*
    |--------------------------------------------------------------------------
    | Maximum Length
    |--------------------------------------------------------------------------
    |
    | The maximum number of Unicode characters in raw search input, including
    | whitespace. This must be a positive integer.
    |
    */

    'max_length' => (int) env('SEARCH_SYNTAX_PARSER_MAX_LENGTH', 1_000),

];
