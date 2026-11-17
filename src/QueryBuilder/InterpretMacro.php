<?php

declare(strict_types=1);

namespace Webong\Cogent\QueryBuilder;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Adds interpret() to every Eloquent model and builder, so a Cogent query can
 * start from the model and read the resource's #[Filterable], #[Sortable] and
 * #[Includable] declarations:
 *
 *     Post::interpret(PostResource::class)->get();
 *     Post::interpret()->get();                              // resource guessed from the model
 *     Post::query()->interpret(PostResource::class)->get();
 */
final class InterpretMacro
{
    public static function register(): void
    {
        if (Builder::hasGlobalMacro('interpret')) {
            return;
        }

        Builder::macro('interpret', self::closure());
    }

    /**
     * The macro itself, bound to the builder it is called on.
     *
     * Exposed so static analysis reads the same signature, which is why this
     * file is excluded from PHPStan: $this only exists once the macro is bound.
     *
     * @return Closure(?string, ?Request): CogentQueryBuilder
     */
    public static function closure(): Closure
    {
        /**
         * @param  class-string|null  $resource
         * @param  Request|null  $request
         */
        return function (?string $resource = null, ?Request $request = null): CogentQueryBuilder {
            return CogentQueryBuilder::for($this, $resource, $request);
        };
    }
}
