<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webong\Cogent\Tests\Fixtures\Collections\LegacyCachedPostCollection;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 */
class LegacyCachedPost extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'posts';

    /**
     * @param  list<static>  $models
     * @return LegacyCachedPostCollection<static>
     */
    public function newCollection(array $models = [])
    {
        return new LegacyCachedPostCollection($models);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
