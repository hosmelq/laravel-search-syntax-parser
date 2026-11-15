<?php

declare(strict_types=1);

use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\RelationFixtures;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Member;
use Workbench\App\Models\Tag;

it('validates configured relation paths and types even without a user clause', function (
    Closure $field,
): void {
    RelationFixtures::seed();

    expect(fn (): array => RelationFixtures::ids('', [$field()]))->toThrow(LogicException::class);
})->with([
    'unknown relation' => [static fn (): Field => Field::string('name')->relation('missing')],
    'not a relation' => [static fn (): Field => Field::string('name')->relation('getTable')],
    'unknown nested relation' => [
        static fn (): Field => Field::string('name')->relation('lines.missing'),
    ],
    'missing morph types' => [static fn (): Field => Field::string('name')->relation('subject')],
    'missing nested morph types' => [
        static fn (): Field => Field::string('name')->relation('lines.product.subject'),
    ],
    'unneeded types' => [
        static fn (): Field => Field::string('name')->relation('lines', types: [Customer::class]),
    ],
    'multiple morphs' => [
        static fn (): Field
            => Field::string('name')
                ->relation('subject.subject', types: [Customer::class, Member::class]),
    ],
    'invalid path for one morph type' => [
        static fn (): Field
            => Field::string('name')
                ->relation('subject.lines', types: [Customer::class, Tag::class]),
    ],
]);
