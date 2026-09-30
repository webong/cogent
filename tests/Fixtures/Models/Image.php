<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Webong\Cogent\Tests\Fixtures\Collections\ImageCollection;

/**
 * @property int $id
 * @property int $imageable_id
 * @property string $imageable_type
 * @property string $url
 */
class Image extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @param  list<static>  $models
     * @return ImageCollection<static>
     */
    public function newCollection(array $models = [])
    {
        return new ImageCollection($models);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
