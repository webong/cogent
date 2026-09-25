<?php

declare(strict_types=1);

namespace Webong\Fluent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webong\Fluent\Tests\Fixtures\Collections\CachedPostCollection;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 */
class CachedPost extends Model
{
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
