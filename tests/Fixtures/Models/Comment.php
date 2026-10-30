<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\UseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webong\Cogent\Tests\Fixtures\Collections\CommentCollection;
use Webong\Cogent\Tests\Fixtures\Resources\CommentResource;

/**
 * @property int $id
 * @property int $post_id
 * @property string $body
 */
#[UseResource(CommentResource::class)]
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
