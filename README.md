# Laravel + Elasticsearch — Maksimum İnteqrasiya

E-ticarət məhsul axtarışı üzərindən Elasticsearch-in bütün əsas xüsusiyyətlərini nümayiş etdirən Laravel demo layihəsi.

---

## Arxitektura

```
HTTP Request
    │
    ▼
ProductSearchController        (validation, routing)
    │
    ▼
SearchRepositoryInterface      (contract / DI)
    │
    ▼
ProductSearchRepository        (query building — bool, aggs, highlight, geo)
    │
    ▼
ElasticsearchService           (generic ES adapter — CRUD, bulk, index mgmt)
    │
    ▼
Elastic\Elasticsearch\Client   (official PHP client)
    │
    ▼
Elasticsearch


Product Model → ProductObserver → IndexProduct/DeleteProductFromIndex Jobs → ElasticsearchService
```

---

## Tələblər

- PHP 8.3+
- Composer 2
- Elasticsearch 8.x
- SQLite (development) və ya MySQL/PostgreSQL

---

## Quraşdırma

```bash
git clone <repo>
cd elastic_search

composer install

cp .env.example .env
php artisan key:generate

# SQLite yarat
touch database/database.sqlite

# .env faylında ES bağlantısını konfiqurasiya et:
# ELASTICSEARCH_HOST=localhost:9200
# ELASTICSEARCH_SSL_VERIFY=false

# Migrasiyanı işlət
php artisan migrate

# Elasticsearch indeksini yarat
php artisan elasticsearch:create-index

# 150 məhsul yarat + ES-ə bulk index et (ES işləyən olduqda)
php artisan db:seed
```

---

## Mühit Dəyişənləri

| Dəyişən | Default | Açıqlama |
|---------|---------|----------|
| `ELASTICSEARCH_HOST` | `localhost:9200` | ES host:port |
| `ELASTICSEARCH_USERNAME` | - | Basic auth istifadəçi adı |
| `ELASTICSEARCH_PASSWORD` | - | Basic auth şifrə |
| `ELASTICSEARCH_API_KEY` | - | API key auth (üstünlüklü) |
| `ELASTICSEARCH_CLOUD_ID` | - | Elastic Cloud ID |
| `ELASTICSEARCH_RETRIES` | `2` | Xəta halında retry sayı |
| `ELASTICSEARCH_SSL_VERIFY` | `true` | SSL sertifikatı yoxlanışı |
| `ELASTICSEARCH_PRODUCTS_INDEX` | `products` | İndeks adı |
| `QUEUE_CONNECTION` | `sync` | `database` və ya `redis` istifadə edin |

---

## Artisan Əmrləri

```bash
# İndeks yarat (config/elasticsearch.php-dən oxuyur)
php artisan elasticsearch:create-index
php artisan elasticsearch:create-index --force    # mövcuddursa sil, yenidən yarat

# İndeks sil
php artisan elasticsearch:delete-index products --force

# Bütün məhsulları yenidən indeksləşdir (bulk API ilə)
php artisan elasticsearch:reindex
php artisan elasticsearch:reindex --fresh         # indeksi sil+yarat, sonra doldur
php artisan elasticsearch:reindex --chunk=200     # hər bulk request-də 200 sənəd

# Mapping yenilə (yeni sahə əlavə etmək üçün — reindex tələb etmir)
php artisan elasticsearch:update-mapping
```

---

## API Endpointlər

### Axtarış — `GET /api/products/search`

**Query Parametrləri:**

| Parametr | Tip | Nümunə | Açıqlama |
|----------|-----|--------|----------|
| `q` | string | `samsung` | Azad mətn axtarışı |
| `category` | string | `electronics` | Tək kateqoriya filteri |
| `categories[]` | array | `categories[]=electronics&categories[]=books` | Çoxlu kateqoriya (OR) |
| `brand` | string | `Samsung` | Brend filteri |
| `brands[]` | array | `brands[]=Samsung&brands[]=Apple` | Çoxlu brend (OR) |
| `tags[]` | array | `tags[]=wireless` | Tag filteri (AND) |
| `price_min` | float | `100` | Minimum qiymət |
| `price_max` | float | `500` | Maksimum qiymət |
| `in_stock` | bool | `1` | Yalnız stokda olanlar |
| `sort` | string | `price_asc` | Sıralama (aşağıya bax) |
| `page` | int | `2` | Səhifə nömrəsi |
| `per_page` | int | `20` | Səhifədə nəticə sayı (max: 100) |
| `from_date` | date | `2024-01-01` | Tarix filteri (created_at >=) |
| `to_date` | date | `2024-12-31` | Tarix filteri (created_at <=) |
| `geo_lat` | float | `40.7128` | Geo-məsafə filteri üçün en dairəsi |
| `geo_lon` | float | `-74.0060` | Geo-məsafə filteri üçün uz dairəsi |
| `geo_distance` | string | `50km` | Radius (məs: `50km`, `100m`, `30mi`) |

**Sort Seçimləri:** `relevance` `price_asc` `price_desc` `newest` `oldest` `name` `stock_desc`

**Nümunə Sorğular:**

```bash
# Sadə axtarış
curl "http://localhost:8000/api/products/search?q=samsung"

# Filtrli axtarış
curl "http://localhost:8000/api/products/search?q=phone&category=electronics&price_max=500&in_stock=1&sort=price_asc"

# Çoxlu kateqoriya
curl "http://localhost:8000/api/products/search?categories[]=electronics&categories[]=books"

# Geo-məsafə
curl "http://localhost:8000/api/products/search?geo_lat=40.7128&geo_lon=-74.0060&geo_distance=50km"

# Tarix aralığı
curl "http://localhost:8000/api/products/search?from_date=2024-01-01&to_date=2024-06-30"
```

**Cavab Strukturu:**

```json
{
  "data": [
    {
      "_score": 2.5,
      "id": 1,
      "name": "Samsung Galaxy S24",
      "category": "electronics",
      "brand": "Samsung",
      "price": 799.99,
      "stock": 150,
      "tags": ["wireless", "5G", "smartphone"],
      "highlighted_name": "<em>Samsung</em> Galaxy S24",
      "highlighted_description": "..."
    }
  ],
  "total": 47,
  "per_page": 15,
  "current_page": 1,
  "last_page": 4,
  "aggregations": {
    "categories": [{"name": "electronics", "count": 45}],
    "brands": [{"name": "Samsung", "count": 20}],
    "tags": [{"tag": "wireless", "count": 30}],
    "price_ranges": [
      {"label": "under_50", "from": null, "to": 50, "count": 10},
      {"label": "50_to_200", "from": 50, "to": 200, "count": 25}
    ],
    "price_stats": {"avg": 299.99, "min": 9.99, "max": 2499.99},
    "total_stock": 5000,
    "unique_brands": 8
  },
  "took_ms": 3,
  "max_score": 2.5
}
```

---

### Tək Məhsul — `GET /api/products/{id}`

```bash
curl "http://localhost:8000/api/products/1"
```

---

### Avtotamamlama — `GET /api/products/suggest?q={prefix}`

Edge n-gram ilə prefix axtarışı (minimum 2 simvol).

```bash
curl "http://localhost:8000/api/products/suggest?q=sam"
# {"suggestions": ["Samsung Galaxy S24", "Samsung TV 4K", "Samsung Laptop Pro"]}
```

---

## Elasticsearch Xüsusiyyətlər — Ətraflı

### 1. Full-Text Axtarış
```php
'multi_match' => [
    'query'     => 'samsung phone',
    'fields'    => ['name^3', 'brand^2', 'description', 'tags'],
    'type'      => 'best_fields',
    'fuzziness' => 'AUTO',  // typo toleransı
]
```

### 2. Bool Sorğu (must + filter + should)
- **must** — relevance score-a təsir edir (tam mətn axtarışı)
- **filter** — score-a təsir etmir, cache-lənir (daha sürətli)
- **should** — bonus score (phrase match üçün)

### 3. Aggregasiyalar
- `terms` — kateqoriya, brend, tag sayımları (faceted navigation)
- `range` — qiymət aralıqları
- `avg`, `min`, `max` — qiymət statistikası
- `sum` — ümumi stok
- `cardinality` — unikal brend sayı

### 4. Highlight
Uyğun gələn sözlər `<em>` teqləri ilə əhatə olunur:
```
"highlighted_name": "<em>Samsung</em> Galaxy Watch"
```

### 5. Geo-Distance
```php
'geo_distance' => [
    'distance' => '50km',
    'location' => ['lat' => 40.71, 'lon' => -74.00],
]
```

### 6. Autocomplete (Edge N-gram)
`name.autocomplete` sub-field `edge_ngram` tokenizer istifadə edir:
- `min_gram: 2`, `max_gram: 20`
- "sa" yazdıqda "Samsung", "Sanyo" tapar

### 7. Observer + Queue Pattern
```
Product::save() → ProductObserver → IndexProduct job (queue) → ElasticsearchService
```
ES xətası DB yazısına mane olmur. Jobs 3 dəfə retry edir.

### 8. Bulk İndeksleme
Seeder və reindex əmri bulk API istifadə edir — fərdi index çağırışlarından 10-50x sürətli.

---

## Testlər

```bash
# Bütün testlər (ES server tələb etmir — Mockery ilə mock edilib)
php artisan test

# Yalnız unit testlər
php artisan test --testsuite=Unit

# Yalnız feature testlər
php artisan test --testsuite=Feature

# Coverage report
php artisan test --coverage
```

---

## Layihə Strukturu

```
app/
├── Console/Commands/          Artisan əmrləri (create-index, reindex, ...)
├── Contracts/
│   └── SearchRepositoryInterface.php
├── Http/Controllers/Api/
│   └── ProductSearchController.php
├── Jobs/
│   ├── IndexProduct.php       Async ES indexing
│   └── DeleteProductFromIndex.php
├── Models/
│   └── Product.php            toSearchArray(), getSearchIndex()
├── Observers/
│   └── ProductObserver.php    created/updated/deleted → dispatch jobs
├── Providers/
│   ├── AppServiceProvider.php      Observer qeydiyyatı
│   └── ElasticsearchServiceProvider.php  DI bindings
└── Services/
    ├── ElasticsearchService.php         Generic ES adapter
    └── Repositories/
        └── ProductSearchRepository.php  Domain-specific query builder

config/
└── elasticsearch.php          Bağlantı + analyzer + mapping konfiqurasiyası

tests/
├── Feature/
│   ├── ProductSearchTest.php  API endpoint testləri
│   ├── ProductIndexingTest.php Observer/Job testləri
│   └── AggregationTest.php    Aggregasiya format testləri
└── Unit/
    ├── ElasticsearchServiceTest.php
    └── ProductSearchRepositoryTest.php  Query builder testləri
```
