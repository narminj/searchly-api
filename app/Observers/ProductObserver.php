<?php

namespace App\Observers;

use App\Jobs\DeleteProductFromIndex;
use App\Jobs\IndexProduct;
use App\Models\Product;

/**
 * Dispatches queue jobs to sync Product changes to Elasticsearch.
 * Using jobs (not direct ES calls) decouples ES availability from DB write success.
 *
 * Invariant: the index only ever contains ACTIVE products. Deactivation
 * removes the document; reactivation re-indexes it.
 */
class ProductObserver
{
    /**
     * Only changes to these fields require an Elasticsearch sync.
     * Click-counter increments bypass model events on purpose (query-builder
     * increment) — popularity reaches ES via the nightly in-place reindex.
     */
    private const SEARCHABLE_FIELDS = [
        'tenant_id', 'name', 'description', 'category', 'brand', 'price', 'stock',
        'tags', 'is_active', 'popularity', 'latitude', 'longitude',
    ];

    public function created(Product $product): void
    {
        if ($product->is_active) {
            IndexProduct::dispatch($product);
        }
    }

    public function updated(Product $product): void
    {
        if (! $product->wasChanged(self::SEARCHABLE_FIELDS)) {
            return;
        }

        // Deactivated, or edited while inactive: make sure it's out of the
        // index. The delete job is idempotent (404 = already gone).
        if (! $product->is_active) {
            DeleteProductFromIndex::dispatch($product->id, $product->getSearchIndex());

            return;
        }

        IndexProduct::dispatch($product);
    }

    public function deleted(Product $product): void
    {
        // Pass primitives only — model is already gone from the DB
        DeleteProductFromIndex::dispatch($product->id, $product->getSearchIndex());
    }
}
