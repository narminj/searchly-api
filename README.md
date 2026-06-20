# Searchly — Production-Grade Elasticsearch Search Platform (Laravel 12)

> A reference implementation of **enterprise search** on top of Laravel 12 and Elasticsearch 8 — multilingual analysis (Azerbaijani + English), synonyms, faceted navigation, autocomplete, "did you mean", relevance tuning, search analytics, multi-tenancy and **zero-downtime versioned index migrations**.

<p>
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white">
  <img alt="Laravel" src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white">
  <img alt="Elasticsearch" src="https://img.shields.io/badge/Elasticsearch-8.15-005571?logo=elasticsearch&logoColor=white">
  <img alt="Docker" src="https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white">
  <img alt="OpenAPI" src="https://img.shields.io/badge/OpenAPI-Swagger-85EA2D?logo=swagger&logoColor=black">
  <img alt="PHPUnit" src="https://img.shields.io/badge/PHPUnit-72%20tests-3776AB?logo=php&logoColor=white">
  <img alt="License" src="https://img.shields.io/badge/License-MIT-green">
</p>

---

## The problem it solves

`LIKE '%query%'` does not scale and cannot relevance-rank. Real e-commerce search has to tolerate typos, understand synonyms across languages, fold diacritics, rank fresh and in-stock items higher, power faceted filtering without breaking facet counts, autocomplete in milliseconds, suggest corrections on zero results, and **ship mapping changes without downtime**. Searchly is a complete, production-shaped backend that does all of this against an e-commerce catalogue, structured so the same patterns drop into any domain (orders, listings, documents).

## Senior backend skills demonstrated

This repository is a portfolio piece. It deliberately showcases what separates "I called the Elasticsearch client" from "I run search in production":

- **Search relevance engineering** — `function_score` with Gaussian recency decay, in-stock weighting and a `rank_feature` popularity signal fed by real click analytics.
- **Multilingual IR** — custom analyzer chains for Azerbaijani and English (diacritic folding, Turkish-aware lowercasing, language-specific stopwords/stemming) and an updateable `synonym_graph`.
- **Zero-downtime operations** — every index lives behind an **alias**; schema changes ship as a new versioned index + atomic alias swap with document-count validation.
- **Clean architecture** — Controller → `SearchRepositoryInterface` (DI contract) → `ProductSearchRepository` (query building) → generic `ElasticsearchService` adapter → official client. Domain logic and transport are fully separated.
- **Asynchronous, consistent indexing** — Observer-driven, queued, `afterCommit`, `ShouldBeUniqueUntilProcessing`; an Elasticsearch outage never blocks or fails a database write.
- **Operability** — health probes, rate limiting, a search-analytics index, p95-latency / zero-result reporting CLIs, and a data-driven synonym-enrichment loop.
- **Multi-tenancy** — tenant term-filtering, tenant-scoped completion contexts and filtered+routed per-tenant aliases, all behind a feature flag with a safe rollout order.
- **Tested without a live cluster** — 72 tests / 242 assertions; Elasticsearch is mocked with Mockery so the suite runs anywhere.

---

## Tech stack

| Layer | Technology |
|-------|-----------|
| Language / Framework | PHP 8.3+ (tested on 8.4), Laravel 12 |
| Search engine | Elasticsearch 8.15 (official `elastic/elasticsearch` PHP client) |
| Persistence | SQLite (dev) / MySQL / PostgreSQL — source of truth for writes |
| Queue & cache | Database driver (Redis-compatible) — async indexing + unique job locks |
| API docs | OpenAPI / Swagger (`darkaonline/l5-swagger`) |
| Infra | Docker Compose (single-node ES 8.15.5), systemd worker, cron scheduler |
| Testing | PHPUnit 11 + Mockery |

---

## Architecture

```
HTTP request
   │
   ▼
ProductSearchController          validation · rate-limit · fires SearchPerformed
   │
   ▼
SearchRepositoryInterface        contract (dependency-injection seam)
   │
   ▼
ProductSearchRepository          query building — bool, function_score, aggs,
   │                             post_filter, highlight, suggest, search_after
   ▼
ElasticsearchService             generic ES adapter — CRUD, bulk, index/alias mgmt
   │
   ▼
Elastic\Elasticsearch\Client     official PHP client
   │
   ▼
Elasticsearch 8.x

Write path:   Product::save() → ProductObserver → IndexProduct / DeleteProductFromIndex
              (queued · afterCommit · unique) → ElasticsearchService
Analytics:    SearchPerformed event → LogSearchPerformed (queued) → search_logs index
Popularity:   POST /click → popularity++ (DB) → nightly 04:10 reindex → ES rank_feature
```

Indexes are **versioned behind an alias**: `products` → `products_v2`. A new mapping means a new physical index plus an atomic alias swap (`elasticsearch:migrate`) — **zero downtime**.

---

## Key features

**Search & relevance**
- Full-text search across name / description / category / brand / tags with fuzziness and synonym expansion
- `function_score` relevance: recency decay (gauss, 90-day scale) × in-stock boost, plus a `rank_feature` popularity boost on text queries
- Multi-select **faceted navigation** via `post_filter` (selecting a category doesn't zero out the other facet counts)
- Aggregations: category/brand/tag terms, price ranges & stats (avg/min/max/sum), total stock, unique brands (cardinality)
- Bool queries (`must` scoring / `filter` cached / `should` phrase bonus), result highlighting (`<em>`), geo-distance radius filter
- Sorting presets (relevance, price, newest/oldest, name, stock) each with a deterministic `id` tiebreaker

**Autocomplete & suggestions**
- Completion suggester (in-memory FST, ~1 ms) with fuzziness, plus `search_as_you_type` bool-prefix fallback
- Phrase-suggester **"did you mean"** appended automatically on zero-result queries (built from an un-stemmed field so it never suggests broken word stems)

**Pagination**
- Offset pagination for shallow pages **and** `search_after` cursor pagination (base64, server-validated) to blow past `max_result_window` for deep/infinite scroll

**Multilingual analysis (AZ/EN)**
- `az_fold` char filter (`ə→e, ı→i, ş→s, ç→c, ğ→g, ö→o, ü→u`), Turkish-aware lowercase, folded-form Azerbaijani stopwords, English stemming
- Per-language sub-fields (`name`, `name.az`, `name.sayt`, `name.dym`, `name.keyword`) generated from a single source field

**Synonyms**
- Dictionary version-controlled in `config/elasticsearch.php`, pushed via the ES 8.10+ Synonyms API as an **updateable** `synonym_graph` — changes take effect immediately, **no reindex**

**Operations & analytics**
- `/api/health` cluster probe, per-route IP rate limiting (search 60/min, suggest 120/min)
- Every search logged to a `search_logs` index (queued, PII-free session hash) → top/zero-result query and p95-latency reports
- Data-driven loop: zero-result queries surface as paste-ready synonym candidates

**Multi-tenancy (feature-flagged)**
- Per-request `tenant_id` term filter, tenant **category context** on the completion suggester, and `elasticsearch:tenant-alias` filtered+routed per-tenant aliases

---

## API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET`  | `/api/health` | App + Elasticsearch cluster health probe |
| `GET`  | `/api/products/search` | Full search: free text, filters, sorting, facets, aggregations, highlights, geo |
| `GET`  | `/api/products/suggest` | Autocomplete (completion + search-as-you-type), min 2 chars |
| `GET`  | `/api/products/{id}` | Single product document by ID |
| `POST` | `/api/products/{id}/click` | Click-through tracking → `popularity++` (atomic), `204 No Content` |

Interactive OpenAPI docs are served at `/api/documentation` (Swagger UI via l5-swagger).

<details>
<summary><b>Key <code>GET /api/products/search</code> parameters</b></summary>

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Free text (AZ/EN, fuzzy, synonym expansion) |
| `category` / `categories[]` | string / array | Single / multi category (OR) |
| `brand` / `brands[]` | string / array | Single / multi brand (OR) |
| `tags[]` | array | Tag filter (AND) |
| `price_min` / `price_max` | float | Price range |
| `in_stock` | bool | Only items in stock |
| `sort` | string | `relevance` `price_asc` `price_desc` `newest` `oldest` `name` `stock_desc` |
| `page` / `per_page` | int | Pagination (`per_page` ≤ 100) |
| `cursor` | string | `search_after` cursor for deep/infinite scroll |
| `with_aggs` | bool | Include aggregations (default 1; send 0 on page > 1 to halve latency) |
| `geo_lat` / `geo_lon` / `geo_distance` | float / string | Geo-distance filter (`50km`, `100m`, `30mi`) |

The response includes `data[]` (with `_score` + `highlighted_name`), `total`, pagination, `next_cursor`, `aggregations`, `took_ms`, and `suggested_query` on zero results.
</details>

---

## Installation & setup

**Requirements:** PHP 8.3+, Composer 2, Docker (for Elasticsearch), SQLite or MySQL/PostgreSQL.

```bash
# 1. Start Elasticsearch (from the directory containing docker-compose.yml)
docker compose up -d                       # ES 8.15.5 on 127.0.0.1:9200

# 2. Dependencies + environment
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite

# 3. Schema + cache/queue tables
php artisan migrate

# 4. Push the synonym dictionary to ES (BEFORE creating the index — the analyzer references it)
php artisan elasticsearch:synonyms products

# 5. Create the versioned index + alias
php artisan elasticsearch:migrate products

# 6. Seed products + bulk index
php artisan db:seed

# 7. Run the queue worker (async indexing)
php artisan queue:work --queue=indexing,default
```

In production the worker runs under **systemd** (`searchly-queue.service`), Elasticsearch under Docker, and the scheduler under cron (`schedule:run` → nightly 04:10 popularity reindex).

### Artisan command reference

```bash
# Index lifecycle
php artisan elasticsearch:create-index products      # v1 + alias bootstrap
php artisan elasticsearch:migrate products           # new version + atomic alias swap + count validation
php artisan elasticsearch:migrate products --prune   # drop superseded versions
php artisan elasticsearch:reindex products           # in-place bulk re-population
php artisan elasticsearch:update-mapping products    # add fields without a reindex

# Synonyms (dictionary versioned in config/elasticsearch.php)
php artisan elasticsearch:synonyms products          # push to ES (immediate, no reindex)
php artisan elasticsearch:synonyms products --show

# Search analytics
php artisan elasticsearch:search-stats --days=7              # top + zero-result queries, avg/p95 latency
php artisan elasticsearch:synonym-suggestions --days=30      # zero-result → synonym candidates (paste-ready)

# Multi-tenancy
php artisan elasticsearch:tenant-alias acme                  # filtered + routed alias: products__acme
```

---

## Environment variables (`.env.example`)

| Variable | Default | Description |
|----------|---------|-------------|
| `ELASTICSEARCH_HOST` | `http://localhost:9200` | **Must** include the scheme (the client rejects a bare `host:port`) |
| `ELASTICSEARCH_API_KEY` | – | API-key auth (preferred for Elastic Cloud) |
| `ELASTICSEARCH_USERNAME` / `_PASSWORD` | – | Basic auth |
| `ELASTICSEARCH_CLOUD_ID` | – | Elastic Cloud ID |
| `ELASTICSEARCH_RETRIES` | `2` | Retries on failure |
| `ELASTICSEARCH_SSL_VERIFY` | `false` | TLS certificate verification |
| `ELASTICSEARCH_PRODUCTS_INDEX` | `products` | Product alias name |
| `ELASTICSEARCH_SEARCH_LOGS_INDEX` | `search_logs` | Analytics index name |
| `ELASTICSEARCH_REPLICAS` | `0` | `0` on single-node (a 1-replica index stays *yellow*) |
| `CACHE_STORE` | `database` | Laravel 12 reads `CACHE_STORE`; unique job locks need the `cache_locks` table |
| `QUEUE_CONNECTION` | `database` | Never `sync` in prod — every write would block on Elasticsearch |
| `MULTI_TENANCY_ENABLED` | `false` | Gates all tenant behaviour; enable **only after** migrating + reindexing with `tenant_id` |
| `FRONTEND_URL` | `http://localhost:5173` | SPA origin allowed by CORS |

---

## Testing

```bash
php artisan test                 # 72 tests / 242 assertions — ES mocked via Mockery (no server needed)
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

`phpunit.xml` pins `QUEUE_CONNECTION=database` (not `sync`) so tests can never accidentally write to a live cluster.

---

## Advanced concepts demonstrated

`function_score` relevance tuning · `rank_feature` popularity signals · custom multilingual analyzer chains · `synonym_graph` (updateable, Synonyms API) · `post_filter` multi-select faceting · terms/range/stats/cardinality aggregations · completion suggester with category contexts · `search_as_you_type` · phrase-suggester "did you mean" · `search_after` cursor pagination · `geo_distance` queries · **alias-based zero-downtime versioned index migrations** · bulk indexing · observer-driven queued async indexing (`afterCommit`, unique jobs) · event-sourced search analytics · multi-tenancy (term filters, suggester contexts, filtered+routed aliases) · repository pattern over a generic ES adapter · rate limiting · OpenAPI documentation.

## License

MIT
