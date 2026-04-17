<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\ElasticsearchService;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class ProductSeeder extends Seeder
{
    public function __construct(private readonly ElasticsearchService $es) {}

    public function run(): void
    {
        // Suppress observer events during DB seeding so we can do one bulk ES call
        Model::withoutObservers(function () {
            Product::factory()->count(150)->create();
        });

        $this->command->info('Created 150 products in database.');

        // Bulk index all products into Elasticsearch
        $index    = config('elasticsearch.indices.products.name');
        $products = Product::all();

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
