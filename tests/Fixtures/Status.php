<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

enum Status: string
{
    case Closed = 'closed';
    case Open = 'open';
    case Pending = 'pending';
}
