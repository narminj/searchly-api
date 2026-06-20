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
    | Default Tenant
    |--------------------------------------------------------------------------
    |
    | Multi-tenancy: requests resolve their tenant from the X-Tenant-ID header,
    | falling back to this value. Existing single-tenant data lives under
    | 'default', so omitting the header preserves the original behaviour.
    |
    */
    'default_tenant' => env('DEFAULT_TENANT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy switch
    |--------------------------------------------------------------------------
    |
    | Off by default so the tenant_id term filter, completion tenant context and
    | tenant-scoped clicks stay dormant until the data layer is migrated (DB
    | column + a reindex that populates tenant_id, plus the context-enabled
    | mapping). Enable ONLY after running: php artisan migrate &&
    | php artisan elasticsearch:migrate products. Flipping it on before the
    | reindex would filter every search down to zero results.
    |
    */
    'multi_tenancy' => (bool) env('MULTI_TENANCY_ENABLED', false),

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
    | Synonym Sets (ES 8.10+ Synonyms API)
    |--------------------------------------------------------------------------
    |
    | The dictionary lives in version control here and is pushed to ES with
    | `php artisan elasticsearch:synonyms`. Updates take effect immediately —
    | the set is referenced by an updateable search-time synonym_graph filter,
    | so no reindex is needed.
    |
    | Rule terms are analyzed by the filters preceding the synonym filter in
    | each analyzer chain, so natural spellings (ə, ü, ç…) are fine here.
    |
    */
    'synonyms' => [
        'products' => [
            'set_id' => 'products-synonyms',
            'rules'  => [
                ['id' => 'phone',      'synonyms' => 'telefon, phone, smartfon, smartphone, mobil telefon'],
                ['id' => 'laptop',     'synonyms' => 'noutbuk, laptop, notebook, kompüter'],
                ['id' => 'tv',         'synonyms' => 'televizor, tv, television'],
                ['id' => 'headphones', 'synonyms' => 'qulaqlıq, headphones, headphone, nausnik'],
                ['id' => 'speaker',    'synonyms' => 'dinamik, speaker, kalonka'],
                ['id' => 'watch',      'synonyms' => 'saat, watch, smartwatch'],
                ['id' => 'shoes',      'synonyms' => 'ayaqqabı, shoes, sneakers, krossovka'],
                ['id' => 'tshirt',     'synonyms' => 'köynək, t-shirt, tshirt, futbolka'],
                ['id' => 'book',       'synonyms' => 'kitab, book, novel'],
                ['id' => 'perfume',    'synonyms' => 'ətir, perfume, parfum'],
                ['id' => 'camera',     'synonyms' => 'kamera, camera, fotoaparat'],
                ['id' => 'bicycle',    'synonyms' => 'velosiped, bicycle, bike'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Definitions
    |--------------------------------------------------------------------------
    |
    | 'name' is the ALIAS the application reads/writes. Physical indices are
    | versioned ({name}_v1, _v2…) and swapped atomically by
    | `php artisan elasticsearch:migrate`.
    |
    | NOTE: the synonym set above must exist in ES before this index can be
    | created (the synonym_graph filter references it) — run
    | `elasticsearch:synonyms` before `elasticsearch:migrate`.
    |
    */
    'indices' => [

        'products' => [
            'name' => env('ELASTICSEARCH_PRODUCTS_INDEX', 'products'),

            'settings' => [
                'number_of_shards'   => 1,
                // 0 on a single-node cluster (replicas could never allocate);
                // raise to 1+ when the cluster gains nodes
                'number_of_replicas' => (int) env('ELASTICSEARCH_REPLICAS', 0),
                'analysis'           => [

                    'char_filter' => [
                        // Azerbaijani → ASCII folding. Users on EN keyboards
                        // type "ucun" for "üçün" — folding BOTH index and
                        // search sides makes them converge.
                        'az_fold' => [
                            'type'     => 'mapping',
                            'mappings' => [
                                'ə=>e', 'Ə=>e', 'ı=>i', 'İ=>i', 'ö=>o', 'Ö=>o',
                                'ü=>u', 'Ü=>u', 'ş=>s', 'Ş=>s', 'ç=>c', 'Ç=>c',
                                'ğ=>g', 'Ğ=>g',
                            ],
                        ],
                    ],

                    'filter' => [
                        // Default lowercase breaks AZ/TR dotted/dotless I
                        // (İ→i, I→ı); the turkish variant handles both
                        'az_lowercase' => ['type' => 'lowercase', 'language' => 'turkish'],

                        // ES has no built-in _azerbaijani_ stopword set.
                        // Terms are listed in FOLDED form — az_fold runs first
                        // in the chain, so only folded forms ever reach here.
                        'az_stop' => [
                            'type'      => 'stop',
                            'stopwords' => [
                                've', 'ile', 'bu', 'bir', 'ucun', 'ki', 'da', 'de',
                                'o', 'men', 'sen', 'biz', 'siz', 'onlar', 'amma',
                                'ancaq', 'hem', 'ya', 'yaxud', 'cox', 'her',
                            ],
                        ],

                        'en_stop'    => ['type' => 'stop', 'stopwords' => '_english_'],
                        'en_stemmer' => ['type' => 'stemmer', 'language' => 'english'],

                        // Search-time only (updateable filters require it);
                        // dictionary managed via elasticsearch:synonyms
                        'product_synonyms' => [
                            'type'         => 'synonym_graph',
                            'synonyms_set' => 'products-synonyms',
                            'updateable'   => true,
                        ],
                    ],

                    'analyzer' => [
                        // Folding only — no stopwords, no stemming. Used where
                        // raw-but-normalized tokens are needed: search-as-you-
                        // type, completion, did-you-mean suggestions.
                        'fold_analyzer' => [
                            'type'        => 'custom',
                            'tokenizer'   => 'standard',
                            'char_filter' => ['az_fold'],
                            'filter'      => ['az_lowercase'],
                        ],

                        // Azerbaijani chain. No stemmer on purpose: ES ships no
                        // Azerbaijani stemmer and the Turkish one over-stems AZ —
                        // fuzziness + synonyms carry that weight instead.
                        'az_analyzer' => [
                            'type'        => 'custom',
                            'tokenizer'   => 'standard',
                            'char_filter' => ['az_fold'],
                            'filter'      => ['az_lowercase', 'az_stop'],
                        ],
                        'az_search_analyzer' => [
                            'type'        => 'custom',
                            'tokenizer'   => 'standard',
                            'char_filter' => ['az_fold'],
                            'filter'      => ['az_lowercase', 'az_stop', 'product_synonyms'],
                        ],

                        // English chain with stemming. Synonyms expand before
                        // the stemmer so expansions get stemmed like documents.
                        'en_analyzer' => [
                            'type'      => 'custom',
                            'tokenizer' => 'standard',
                            'filter'    => ['lowercase', 'asciifolding', 'en_stop', 'en_stemmer'],
                        ],
                        'en_search_analyzer' => [
                            'type'      => 'custom',
                            'tokenizer' => 'standard',
                            'filter'    => ['lowercase', 'asciifolding', 'en_stop', 'product_synonyms', 'en_stemmer'],
                        ],
                    ],
                ],
            ],

            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'integer'],

                    // Single-tenant today; mapped now so multi-tenancy (filtered
                    // aliases + routing) needs no reindex later
                    'tenant_id' => ['type' => 'keyword'],

                    'name' => [
                        'type'            => 'text',
                        'analyzer'        => 'en_analyzer',
                        'search_analyzer' => 'en_search_analyzer',
                        'fields'          => [
                            // Azerbaijani view of the same content
                            'az' => [
                                'type'            => 'text',
                                'analyzer'        => 'az_analyzer',
                                'search_analyzer' => 'az_search_analyzer',
                            ],
                            'keyword' => [
                                'type'         => 'keyword',
                                'ignore_above' => 256,
                            ],
                            // Typo-tolerant search-as-you-type (bool_prefix
                            // queries across .sayt, ._2gram, ._3gram)
                            'sayt' => [
                                'type'     => 'search_as_you_type',
                                'analyzer' => 'fold_analyzer',
                            ],
                            // Un-stemmed base for the "did you mean" phrase
                            // suggester (suggesting from stemmed tokens would
                            // surface broken words like "smartphon")
                            'dym' => [
                                'type'     => 'text',
                                'analyzer' => 'fold_analyzer',
                            ],
                        ],
                    ],

                    'description' => [
                        'type'            => 'text',
                        'analyzer'        => 'en_analyzer',
                        'search_analyzer' => 'en_search_analyzer',
                        'fields'          => [
                            'az' => [
                                'type'            => 'text',
                                'analyzer'        => 'az_analyzer',
                                'search_analyzer' => 'az_search_analyzer',
                            ],
                        ],
                    ],

                    'category' => [
                        'type'            => 'text',
                        'analyzer'        => 'en_analyzer',
                        'search_analyzer' => 'en_search_analyzer',
                        'fields'          => ['keyword' => ['type' => 'keyword', 'ignore_above' => 100]],
                    ],

                    'brand' => [
                        'type'            => 'text',
                        'analyzer'        => 'en_analyzer',
                        'search_analyzer' => 'en_search_analyzer',
                        'fields'          => ['keyword' => ['type' => 'keyword', 'ignore_above' => 100]],
                    ],

                    // scaled_float stores money as scaled integers — no binary
                    // float artifacts in range filters and aggregations
                    'price' => ['type' => 'scaled_float', 'scaling_factor' => 100],

                    'stock' => ['type' => 'integer'],

                    // keyword for exact filters/aggs; .text makes tags count
                    // toward free-text relevance too
                    'tags' => [
                        'type'   => 'keyword',
                        'fields' => [
                            'text' => [
                                'type'            => 'text',
                                'analyzer'        => 'en_analyzer',
                                'search_analyzer' => 'en_search_analyzer',
                            ],
                        ],
                    ],

                    'is_active' => ['type' => 'boolean'],

                    // geo_point enables geo_distance filter queries
                    'location' => ['type' => 'geo_point'],

                    // Fed by search analytics in Phase 3; documents may omit it
                    'popularity' => ['type' => 'rank_feature'],

                    // Completion suggester (in-memory FST, ~1ms lookups).
                    // The 'tenant' category context (read from the tenant_id
                    // field via path) isolates suggestions per tenant — the
                    // query MUST pass the context, which is exactly the
                    // multi-tenancy guarantee we want.
                    'suggest' => [
                        'type'            => 'completion',
                        'analyzer'        => 'fold_analyzer',
                        'search_analyzer' => 'fold_analyzer',
                        'contexts'        => [
                            ['name' => 'tenant', 'type' => 'category', 'path' => 'tenant_id'],
                        ],
                    ],

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

        /*
        | Search analytics. Append-only (auto-generated IDs), written by the
        | queued LogSearchPerformed listener. Feeds the zero-result report
        | (elasticsearch:search-stats) and, later, the synonym dictionary.
        */
        'search_logs' => [
            'name' => env('ELASTICSEARCH_SEARCH_LOGS_INDEX', 'search_logs'),

            'settings' => [
                'number_of_shards'   => 1,
                'number_of_replicas' => (int) env('ELASTICSEARCH_REPLICAS', 0),
            ],

            'mappings' => [
                'properties' => [
                    // keyword first: top-query aggregations are the main read path
                    'query'            => [
                        'type'   => 'keyword',
                        'ignore_above' => 256,
                        'fields' => ['text' => ['type' => 'text']],
                    ],
                    'filters'          => ['type' => 'flattened'],
                    'result_count'     => ['type' => 'integer'],
                    'zero_results'     => ['type' => 'boolean'],
                    'took_ms'          => ['type' => 'integer'],
                    'page'             => ['type' => 'integer'],
                    'suggested_query'  => ['type' => 'keyword', 'ignore_above' => 256],
                    // sha1(ip|user-agent) — groups a session without storing PII
                    'session'          => ['type' => 'keyword'],
                    'created_at'       => [
                        'type'   => 'date',
                        'format' => 'yyyy-MM-dd HH:mm:ss||epoch_millis',
                    ],
                ],
            ],
        ],

    ],

];
