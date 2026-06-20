<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexProduct implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 3 times before marking as failed */
    public int $tries = 3;

    /** Exponential backoff: 10s, 20s, 40s between retries */
    public array $backoff = [10, 20, 40];

    /** The handler re-reads the model from the DB; if it's gone, drop the job silently */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Product $product)
    {
        $this->onQueue('indexing');
        // Never index data from an uncommitted transaction
        $this->afterCommit();
    }

    /**
     * Collapse a burst of edits to the same product into one queued job.
     * Unique only until processing starts — an edit made while the job is
     * running still gets its own follow-up job, so no update is lost.
     */
    public function uniqueId(): string
    {
        return (string) $this->product->id;
    }

    public function handle(ElasticsearchService $es): void
    {
        $es->indexDocument(
            $this->product->getSearchIndex(),
            $this->product->id,
            $this->product->toSearchArray()
        );
    }
}
