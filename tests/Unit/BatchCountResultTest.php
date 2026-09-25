<?php

declare(strict_types=1);

use Webong\Fluent\Support\BatchCountResult;
use Illuminate\Support\Collection;

it('returns counts and truncated flags by integer or string key', function (): void {
    $result = new BatchCountResult(
        counts: new Collection(['abc' => 4, 15 => 2]),
        truncated: new Collection(['abc' => true, 15 => false]),
    );

    expect($result->countFor('abc'))->toBe(4)
        ->and($result->countFor(15))->toBe(2)
        ->and($result->countFor('missing'))->toBe(0)
        ->and($result->isTruncated('abc'))->toBeTrue()
        ->and($result->isTruncated(15))->toBeFalse()
        ->and($result->isTruncated('missing'))->toBeFalse();
});
