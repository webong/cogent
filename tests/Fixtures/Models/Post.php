<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Webong\Cogent\Tests\Fixtures\Collections\PostCollection;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $upper_title
 */
class Post extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @param  list<static>  $models
     * @return PostCollection<static>
     */
    public function newCollection(array $models = [])
    {
        return new PostCollection($models);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    /**
     * @return MorphMany<Tag, $this>
     */
    public function tags(): MorphMany
    {
        return $this->morphMany(Tag::class, 'taggable');
    }

    /**
     * @return MorphMany<Image, $this>
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function upperTitle(): void
    {
        $this->setAttribute('upper_title', Str::upper((string) $this->title));
    }
}
