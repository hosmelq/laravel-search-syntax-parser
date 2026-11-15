<?php

declare(strict_types=1);

return [
    'directive' => [
        'duplicate' => 'Directive ":field" may appear only once.',
        'invalid_clause' => 'Directive ":field" requires a single equality value.',
        'not_top_level' => 'Directive ":field" must be a top-level condition.',
    ],

    'field' => [
        'invalid_value' => 'Invalid value for field ":field".',
        'not_allowed' => 'Field ":field" is not allowed. Allowed fields: :allowed.',
        'not_allowed_none' => 'Field ":field" is not allowed. No fields are allowed.',
        'operator_not_allowed' => 'The ":operation" operation is not allowed for field ":field".',
    ],

    'position' => 'At position :position.',

    'query' => [
        'invalid_type' => 'The search query must be a string.',
        'invalid_utf8' => 'The search query must contain valid UTF-8.',
        'maximum_clauses' => 'The search query exceeds :limit clauses.',
        'maximum_depth' => 'The search query exceeds a depth of :limit.',
        'maximum_length' => 'The search query exceeds :limit characters.',
        'tokenization_failed' => 'The search query is too complex to tokenize.',
    ],

    'syntax' => [
        'expected_token' => 'Expected :expected, received :actual.',
        'expected_value' => 'Expected a search value.',
        'unexpected_end_of_input' => 'Unexpected end of the search query.',
        'unexpected_token' => 'Unexpected token ":token".',
        'unterminated_quote' => 'The quoted search value is not closed.',
    ],

    'wildcard' => [
        'invalid_pattern' => 'Wildcards require equality and cannot be range bounds.',
        'invalid_usage' => 'Use :field:* for existence or NOT :field:* for absence.',
        'leading_not_allowed' => 'Leading wildcards are not allowed.',
        'too_short' => 'Wildcards for field ":field" need at least :minimum literal characters.',
        'word_not_allowed' => 'Wildcards for field ":field" may only end a word, as in "coff*".',
    ],
];
