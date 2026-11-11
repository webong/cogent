<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webong\Cogent\Attributes\CachedRelation;
use Webong\Cogent\Concerns\DefinesCachedRelations;
use Webong\Cogent\Tests\Fixtures\Collections\CommentCollection;
use Webong\Cogent\Tests\Fixtures\Collections\DynamicKeyPostCollection;
use Webong\Cogent\Tests\Fixtures\Support\ConnectionCacheKeyResolver;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 */
class DynamicKeyPost extends Model
{
    use DefinesCachedRelations;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'posts';

    /**
     * @param  list<static>  $models
     * @return DynamicKeyPostCollection<static>
     */
    public function newCollection(array $models = [])
    {
        return new DynamicKeyPostCollection($models);
    }

    protected static function boot()
    {
        parent::boot();

        static::bootDefinesCachedRelations();
    }

    #[CachedRelation(ttl: 60, keyResolver: ConnectionCacheKeyResolver::class)]
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')->cached(
            ttl: 60,
            collection: CommentCollection::class,
            keyResolver: fn (Model $model, mixed $id): string => 'cogent-test:dynamic-comments:'.$model->getConnection()->getName().':'.$id,
        );
    }
}
