<?php

declare(strict_types=1);

/*
 * Compare plain resources against prepared and cached ones on the same data.
 *
 *     vendor/bin/pest benchmarks
 *
 * In-memory SQLite has no network between PHP and the database, so every query
 * here is far cheaper than in production: the query counts carry over, the
 * times are a floor.
 */

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Webong\Cogent\Resources\ResourcePlan;
use Webong\Cogent\Tests\Fixtures\Models\Comment;
use Webong\Cogent\Tests\Fixtures\Models\Post;
use Webong\Cogent\Tests\Fixtures\Models\Tag;
use Webong\Cogent\Tests\Fixtures\Models\User;
use Webong\Cogent\Tests\Fixtures\Resources\PostResource;
use Webong\Cogent\Tests\Fixtures\Resources\RememberedPostResource;
use Webong\Cogent\Tests\TestCase;

uses(TestCase::class);

final class PlainUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tags' => $this->tags->pluck('name')->all(),
            'posts_count' => $this->posts_count ?? $this->posts()->count(),
        ];
    }
}

final class PlainPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => new PlainUserResource($this->author),
            'comments_count' => $this->comments_count ?? $this->comments()->count(),
        ];
    }
}

/**
 * @param  Closure(): mixed  $render
 * @return array{queries: int, ms: float}
 */
function measure(Closure $render, int $runs = 15): array
{
    $times = [];
    $queries = 0;

    for ($run = 0; $run < $runs; $run++) {
        app()->forgetScopedInstances();
        ResourcePlan::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = hrtime(true);
        $render()->response(Request::create('/posts'))->getContent();
        $times[] = (hrtime(true) - $started) / 1e6;

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
    }

    sort($times);

    return ['queries' => $queries, 'ms' => round($times[intdiv($runs, 2)], 2)];
}

it('benchmarks rendering 200 posts', function (): void {
    $authors = collect(range(1, 20))->map(fn (int $index): User => User::query()->create(['name' => 'Author '.$index]));

    foreach ($authors as $author) {
        Tag::query()->create(['taggable_type' => User::class, 'taggable_id' => $author->id, 'name' => 'tag-'.$author->id]);
    }

    foreach (range(1, 200) as $index) {
        $post = Post::query()->create(['user_id' => $authors[$index % 20]->id, 'title' => 'Post '.$index]);

        foreach (range(1, $index % 6) as $comment) {
            Comment::query()->create(['post_id' => $post->id, 'body' => 'Comment '.$comment]);
        }
    }

    $posts = fn () => Post::query()->orderBy('id')->get();

    $rows = [
        'Plain resources, lazy loading' => measure(fn () => PlainPostResource::collection($posts())),
        'Plain resources, eager loading by hand' => measure(fn () => PlainPostResource::collection(
            Post::query()->with(['author' => fn ($query) => $query->withCount('posts'), 'author.tags'])->withCount('comments')->orderBy('id')->get(),
        )),
        'Cogent, prepared' => measure(fn () => PostResource::collection($posts())),
        'Cogent, cache hit' => (function () use ($posts): array {
            RememberedPostResource::collection($posts())->response(Request::create('/posts'));

            return measure(fn () => RememberedPostResource::collection($posts()));
        })(),
    ];

    $table = "| Rendering 200 posts | Queries | Median time |\n| --- | ---: | ---: |\n";

    foreach ($rows as $name => $row) {
        $table .= sprintf("| %s | %d | %.2f ms |\n", $name, $row['queries'], $row['ms']);
    }

    fwrite(STDOUT, PHP_EOL.$table);

    expect($rows['Cogent, prepared']['queries'])->toBeLessThan($rows['Plain resources, lazy loading']['queries'])
        ->and($rows['Cogent, cache hit']['queries'])->toBe(1);
});
