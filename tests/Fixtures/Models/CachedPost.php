<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webong\Cogent\Attributes\CachedRelation;
use Webong\Cogent\Concerns\DefinesCachedRelations;
use Webong\Cogent\Tests\Fixtures\Collections\CachedPostCollection;
use Webong\Cogent\Tests\Fixtures\Collections\CommentCollection;

use function Webong\Cogent\cached;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 */
class CachedPost extends Model
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
     * @return CachedPostCollection<static>
     */
    public function newCollection(array $models = [])
    {
        return new CachedPostCollection($models);
    }

    #[CachedRelation(ttl: 60, key: 'cogent-test:author:{value}')]
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')->cached(
            ttl: 60,
            key: 'cogent-test:comments:{value}',
            collection: CommentCollection::class,
        );
    }

    #[CachedRelation(ttl: 60)]
    public function firstComment(): HasOne
    {
        return $this->hasOne(Comment::class, 'post_id');
    }

    public function commentsThroughTheHelper(): HasMany
    {
        return cached(
            relation: $this->hasMany(Comment::class, 'post_id'),
            ttl: 60,
            key: 'cogent-test:helper-comments:{value}',
            collection: CommentCollection::class,
        );
    }

    public function uncachedComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
