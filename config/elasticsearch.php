<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Elasticsearch Connection
    |--------------------------------------------------------------------------
    */
    'default' => env('ELASTICSEARCH_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Elasticsearch Connections
    |--------------------------------------------------------------------------
    |
    | Supported auth: api_key, basic (username+password), cloud_id, or none.
    | Priority order: api_key > cloud_id > basic > anonymous
    |
    */
    'connections' => [
        'default' => [
            'hosts'            => [env('ELASTICSEARCH_HOST', 'localhost:9200')],
            'username'         => env('ELASTICSEARCH_USERNAME', ''),
            'password'         => env('ELASTICSEARCH_PASSWORD', ''),
            'api_key'          => env('ELASTICSEARCH_API_KEY', ''),
            'cloud_id'         => env('ELASTICSEARCH_CLOUD_ID', ''),
            'retries'          => (int) env('ELASTICSEARCH_RETRIES', 2),
            'ssl_verification' => (bool) env('ELASTICSEARCH_SSL_VERIFY', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Definitions
    |--------------------------------------------------------------------------
    |
    | Each index includes its name (from env), settings (analyzers, shards),
    | and mappings. The Artisan commands read from here to create/update indices.
    |
    */
    'indices' => [

        'products' => [
            'name' => env('ELASTICSEARCH_PRODUCTS_INDEX', 'products'),

            'settings' => [
                'number_of_shards'   => 1,
                'number_of_replicas' => 0,
                'analysis'           => [
                    'analyzer' => [
                        // Standard analyzer with ASCII folding and stop words
                        'product_analyzer' => [
                            'type'      => 'custom',
                            'tokenizer' => 'standard',
                            'filter'    => ['lowercase', 'asciifolding', 'stop'],
                        ],
                        // Edge n-gram analyzer for autocomplete/prefix search
                        'autocomplete_analyzer' => [
                            'type'      => 'custom',
                            'tokenizer' => 'autocomplete_tokenizer',
                            'filter'    => ['lowercase'],
                        ],
                        // Search-time analyzer for autocomplete (no n-gram on query side)
                        'autocomplete_search_analyzer' => [
                            'type'      => 'custom',
                            'tokenizer' => 'standard',
                            'filter'    => ['lowercase'],
                        ],
                    ],
                    'tokenizer' => [
                        'autocomplete_tokenizer' => [
                            'type'        => 'edge_ngram',
                            'min_gram'    => 2,
                            'max_gram'    => 20,
                            'token_chars' => ['letter', 'digit'],
                        ],
                    ],
                ],
            ],

            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'integer'],

                    // Multi-field: text for full-text, keyword for exact/aggs/sort,
                    // autocomplete sub-field for prefix search
                    'name' => [
                        'type'     => 'text',
                        'analyzer' => 'product_analyzer',
                        'fields'   => [
                            'keyword' => [
                                'type'         => 'keyword',
                                'ignore_above' => 256,
                            ],
                            'autocomplete' => [
                                'type'            => 'text',
                                'analyzer'        => 'autocomplete_analyzer',
                                'search_analyzer' => 'autocomplete_search_analyzer',
                            ],
                        ],
                    ],

                    'description' => [
                        'type'     => 'text',
                        'analyzer' => 'product_analyzer',
                    ],

                    'category' => [
                        'type'   => 'text',
                        'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 100]],
                    ],

                    'brand' => [
                        'type'   => 'text',
                        'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 100]],
                    ],

                    'price' => ['type' => 'float'],

                    'stock' => ['type' => 'integer'],

                    // Stored as keyword array for terms aggregations and filtering
                    'tags' => ['type' => 'keyword'],

                    'is_active' => ['type' => 'boolean'],

                    // geo_point enables geo_distance filter queries
                    'location' => ['type' => 'geo_point'],

                    'created_at' => [
                        'type'   => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss||epoch_millis',
                    ],

                    'updated_at' => [
                        'type'   => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss||epoch_millis',
                    ],
                ],
            ],
        ],

    ],

];
