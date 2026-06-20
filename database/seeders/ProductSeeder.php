<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function __construct(private readonly ElasticsearchService $es) {}

    public function run(): void
    {
        // Suppress model events during DB seeding so we can do one bulk ES call
        // instead of 150 observer-dispatched jobs
        Product::withoutEvents(function () {
            Product::factory()->count(150)->create();
        });

        $this->command->info('Created 150 products in database.');

        // Bulk index into Elasticsearch — only active products (the index
        // never holds inactive ones, see ProductObserver)
        $index = config('elasticsearch.indices.products.name');

        if (! $this->es->existsIndex($index)) {
            // Don't let the bulk call auto-create an unmapped index —
            // elasticsearch:migrate builds it with proper settings/mappings
            $this->command->warn("Index/alias '{$index}' does not exist — skipping ES indexing. Run: php artisan elasticsearch:migrate products");

            return;
        }

        $products = Product::where('is_active', true)->get();

        $documents = $products->map(fn (Product $p) => $p->toSearchArray())->all();

        $result = $this->es->bulkIndex($index, $documents);

        $errors = collect($result['items'] ?? [])->filter(fn ($item) => isset($item['index']['error']))->count();

        if ($errors > 0) {
            $this->command->warn("Bulk index completed with {$errors} errors.");
        } else {
            $this->command->info("All {$products->count()} products indexed in Elasticsearch.");
        }
    }
}
