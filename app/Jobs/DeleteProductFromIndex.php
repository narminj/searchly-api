<?php

namespace App\Jobs;

use App\Services\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteProductFromIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 20, 40];

    public function __construct(
        public readonly int $productId,
        public readonly string $index
    ) {}

    public function handle(ElasticsearchService $es): void
    {
        $es->deleteDocument($this->index, $this->productId);
    }
}
