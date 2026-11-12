<?php

declare(strict_types=1);

namespace Webong\Cogent\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $secret
 */
class ResponsePost extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'posts';

    public function upperTitle(): void
    {
        $this->setAttribute('upper_title', mb_strtoupper((string) $this->title));
    }

    public function getUpperTitleAttribute(): string
    {
        return mb_strtoupper((string) $this->title);
    }
}
