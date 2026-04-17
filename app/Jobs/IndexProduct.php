<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 3 times before marking as failed */
    public int $tries = 3;

    /** Exponential backoff: 10s, 20s, 40s between retries */
    public array $backoff = [10, 20, 40];

    public function __construct(public readonly Product $product) {}

    public function handle(ElasticsearchService $es): void
    {
        $es->indexDocument(
            $this->product->getSearchIndex(),
            $this->product->id,
            $this->product->toSearchArray()
        );
    }
}
