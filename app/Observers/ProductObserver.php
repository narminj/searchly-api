<?php

namespace App\Observers;

use App\Jobs\DeleteProductFromIndex;
use App\Jobs\IndexProduct;
use App\Models\Product;

/**
 * Dispatches queue jobs to sync Product changes to Elasticsearch.
 * Using jobs (not direct ES calls) decouples ES availability from DB write success.
 */
class ProductObserver
{
    public function created(Product $product): void
    {
        IndexProduct::dispatch($product);
    }

    public function updated(Product $product): void
    {
        IndexProduct::dispatch($product);
    }

    public function deleted(Product $product): void
    {
        // Pass primitives only — model is already gone from the DB
        DeleteProductFromIndex::dispatch($product->id, $product->getSearchIndex());
    }
}
