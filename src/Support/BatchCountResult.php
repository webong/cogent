<?php

declare(strict_types=1);

namespace Webong\Cogent\Support;

use Illuminate\Support\Collection;

final readonly class BatchCountResult
{
    /**
     * @param  Collection<int|string, int>  $counts
     * @param  Collection<int|string, bool>  $truncated
     */
    public function __construct(
        private Collection $counts,
        private Collection $truncated,
    ) {
    }

    public static function empty(): self
    {
        return new self(new Collection, new Collection);
    }

    public function countFor(int|string $key): int
    {
        return (int) ($this->counts->get((string) $key) ?? $this->counts->get($key) ?? 0);
    }

    public function isTruncated(int|string $key): bool
    {
        return (bool) ($this->truncated->get((string) $key) ?? $this->truncated->get($key) ?? false);
    }

    /**
     * @return Collection<int|string, int>
     */
    public function counts(): Collection
    {
        return $this->counts;
    }

    /**
     * @return Collection<int|string, bool>
     */
    public function truncated(): Collection
    {
        return $this->truncated;
    }
}
