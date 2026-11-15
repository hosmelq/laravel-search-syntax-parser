<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use HosmelQ\SearchSyntaxParser\Laravel\Concerns\HasSearchSyntax;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Expression;
use Override;

class Product extends Model
{
    use HasSearchSyntax;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return list<Field>
     */
    public static function searchFields(): array
    {
        return [
            Field::string('name'),
            Field::date('created')->column('created_at')->nullable(),
            Field::date('available')->column('available_on')->withoutTime()->nullable(),
            Field::in('status', ['open', 'pending', 'closed']),
            Field::string('person')->columns(['first_name', 'last_name'])->nullable(),
            Field::string('description')->nullable(),
            Field::integer('quantity')->nullable(),
            Field::decimal('price', 0, 2)->column('price_cents')->scale(100)->nullable(),
            Field::numeric('rating')->nullable(),
            Field::uuid('uuid')->nullable(),
            Field::string('full_name')->expression(
                fn (string $driver): Expression => new Expression(
                    $driver === 'sqlite'
                        ? "first_name || ' ' || last_name"
                        : "concat(first_name, ' ', last_name)",
                ),
            )->nullable(),
        ];
    }

    /**
     * @return HasManyThrough<Line, self, $this>
     */
    public function childLines(): HasManyThrough
    {
        return $this->hasManyThrough(Line::class, self::class, 'parent_id', 'product_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasOne<Line, $this>
     */
    public function line(): HasOne
    {
        return $this->hasOne(Line::class);
    }

    /**
     * @return HasMany<Line, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }

    /**
     * @return MorphOne<Note, $this>
     */
    public function note(): MorphOne
    {
        return $this->morphOne(Note::class, 'notable');
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function polymorphicTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * @param Builder<static> $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where($query->qualifyColumn('quantity'), '<=', 10);
    }

    /**
     * @return HasOneThrough<Shipment, Line, $this>
     */
    public function shipment(): HasOneThrough
    {
        return $this->hasOneThrough(Shipment::class, Line::class);
    }

    /**
     * @return HasManyThrough<Shipment, Line, $this>
     */
    public function shipments(): HasManyThrough
    {
        return $this->hasManyThrough(Shipment::class, Line::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'available_on' => 'date',
            'created_at' => 'datetime',
            'meta' => 'array',
            'meta_jsonb' => 'array',
            'tags' => 'array',
        ];
    }

    /**
     * @param Builder<static> $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where($query->qualifyColumn('quantity'), '>', 10);
    }

    #[Override]
    protected function searchSyntax(Search $search): Search
    {
        return $search->fields(self::searchFields())->terms([
            'name',
            'description',
            'quantity',
            'rating',
            'uuid',
        ]);
    }
}
