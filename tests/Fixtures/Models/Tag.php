<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $taggable_id
 * @property string $taggable_type
 * @property string $name
 */
class Tag extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return MorphTo<Model, $this>
     */
    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}
