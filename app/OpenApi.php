<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Searchly API',
    version: '1.0.0',
    description: 'Searchly — Elasticsearch-powered product search engine. Full-text search with filters, sorting and aggregations. Autocomplete prefix suggestions and single product lookup by ID.',
    contact: new OA\Contact(email: 'admin@searchly.narmin.dev', name: 'Searchly API Support'),
    license: new OA\License(name: 'MIT', url: 'https://opensource.org/licenses/MIT')
)]
#[OA\Server(url: 'https://api.searchly.narmin.dev/api', description: 'Searchly Production API')]
#[OA\Server(url: 'http://localhost:8000/api', description: 'Local development')]
#[OA\Tag(name: 'Search',   description: 'Full-text product search with filters, sorting and aggregations')]
#[OA\Tag(name: 'Suggest',  description: 'Autocomplete — prefix-based product name suggestions')]
#[OA\Tag(name: 'Products', description: 'Single product retrieval and click tracking')]
#[OA\Tag(name: 'System',   description: 'Health and status')]
class OpenApi {}
