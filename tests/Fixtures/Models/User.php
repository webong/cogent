<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Webong\Cogent\Attributes\CachedRelation;
use Webong\Cogent\Concerns\DefinesCachedRelations;
use Webong\Cogent\Tests\Fixtures\Collections\UserCollection;

/**
 * @property int $id
 * @property string $name
 */
class User extends Model
{
    use DefinesCachedRelations;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @param  list<static>  $models
     * @return UserCollection<static>
     */
    public function newCollection(array $models = [])
    {
        return new UserCollection($models);
    }

    #[CachedRelation]
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    #[CachedRelation(ttl: 60)]
    public function tags(): MorphMany
    {
        return $this->morphMany(Tag::class, 'taggable');
    }
}
