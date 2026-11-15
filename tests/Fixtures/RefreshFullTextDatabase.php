<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait RefreshFullTextDatabase
{
    use RefreshDatabase {
        beginDatabaseTransaction as private beginRefreshDatabaseTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        if (! in_array('full-text', $this->groups(), true)) {
            $this->beginRefreshDatabaseTransaction();

            return;
        }

        // InnoDB full-text indexes can only find committed rows.
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }
}
