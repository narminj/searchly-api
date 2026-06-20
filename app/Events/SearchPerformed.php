<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every product search request. Carries everything the
 * analytics listener needs — primitives only, so queue serialization
 * stays trivial.
 */
class SearchPerformed
{
    use Dispatchable;

    public function __construct(
        public readonly string $query,
        public readonly array $filters,
        public readonly int $resultCount,
        public readonly int $tookMs,
        public readonly int $page,
        public readonly ?string $suggestedQuery,
        public readonly string $session,
    ) {}
}
