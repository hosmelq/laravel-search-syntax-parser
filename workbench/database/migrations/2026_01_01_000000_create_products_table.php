<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        foreach (['customers', 'members'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('name')->nullable();
                $table->nullableMorphs('subject');

                if ($name === 'customers') {
                    $table->softDeletes();
                }
            });
        }

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('tenant_id')->default(1);
            $table->foreignId('customer_id')->nullable()->index();
            $table->foreignId('parent_id')->nullable()->index();
            $table->nullableMorphs('subject');
            $table->string('name');
            $table->string('status');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('description')->nullable();
            $table->integer('quantity')->nullable();
            $table->integer('price_cents')->nullable();
            $table->decimal('rating', 10, 2)->nullable();
            $table->uuid('uuid')->nullable();
            $table->ulid('ulid')->nullable();
            $table->boolean('active')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->softDeletes();
            $table->date('available_on')->nullable();
            $table->json('meta')->nullable();
            $table->json('tags')->nullable();

            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fullText('name')->language('simple');
                $table->fullText('description')->language('simple');
            }

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('meta_jsonb')->nullable();
            }
        });

        Schema::create('lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->index();
            $table->foreignId('customer_id')->nullable()->index();
            $table->foreignId('member_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('alternate_name')->nullable();
            $table->integer('quantity')->nullable();
            $table->integer('price_cents')->nullable();
            $table->string('status')->nullable();
            $table->uuid('uuid')->nullable();
            $table->ulid('ulid')->nullable();
            $table->boolean('active')->nullable();
            $table->boolean('visible')->default(true);
            $table->dateTime('created_at', 6)->nullable();
            $table->date('available_on')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes('deleted_at', 6);
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('line_id')->index();
            $table->string('name')->nullable();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
        });

        Schema::create('product_tag', function (Blueprint $table): void {
            $table->foreignId('product_id')->index();
            $table->foreignId('tag_id')->index();
            $table->string('role')->nullable();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->morphs('notable');
            $table->string('name')->nullable();
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id')->index();
            $table->morphs('taggable');
            $table->string('role')->nullable();
        });
    }
};
