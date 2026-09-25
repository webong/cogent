<?php

declare(strict_types=1);

namespace Webong\Fluent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webong\Fluent\Tests\Fixtures\Collections\CommentCollection;

/**
 * @property int $id
 * @property int $post_id
 * @property string $body
 */
class Comment extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @param  list<static>  $models
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public function newCollection(array $models = [])
    {
        return new CommentCollection($models);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
