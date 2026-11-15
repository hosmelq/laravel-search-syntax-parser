<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests;

use HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures\RefreshFullTextDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshFullTextDatabase;
    use WithWorkbench;
}
