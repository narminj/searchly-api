<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mines zero-result search queries (search_logs) and proposes additions to the
 * synonym dictionary. Read-only: it prints candidates and paste-ready config
 * lines, but never mutates config/elasticsearch.php or Elasticsearch. The human
 * reviews, edits the config, then runs `elasticsearch:synonyms` to apply.
 *
 * Two tiers of candidate generation:
 *   Tier 1 — the query is a folded near-miss (Levenshtein) of a term already in
 *            the dictionary → suggest adding it to that existing rule.
 *   Tier 2 — a loose fuzzy lookup against the product catalog surfaces the
 *            closest category/brand → suggest those as the new rule's target.
 */
class ElasticsearchSynonymSuggestions extends Command
{
    protected $signature = 'elasticsearch:synonym-suggestions
                            {key? : Synonyms config key (default: products)}
                            {--days=30 : Look-back window in days}
                            {--size=30 : Max zero-result queries to inspect}
                            {--min-count=1 : Ignore queries seen fewer than this many times}';

    protected $description = 'Mine zero-result queries and propose synonym-dictionary additions '
        . '(read-only: prints candidates + paste-ready config lines, never mutates anything).';

    /**
     * Azerbaijani character folding — mirrors the az_fold char_filter so that
     * comparisons match how the analyzer sees terms. Uppercase forms are mapped
     * before lowercasing to dodge the dotted-İ Unicode edge case.
     */
    private const FOLD = [
        'Ə' => 'e', 'ə' => 'e', 'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ö' => 'o', 'ö' => 'o', 'Ü' => 'u', 'ü' => 'u', 'Ş' => 's',
        'ş' => 's', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g',
    ];

    public function handle(ElasticsearchService $es): int
    {
        $key = $this->argument('key') ?? 'products';
        $days = max(1, (int) $this->option('days'));
        $size = max(1, (int) $this->option('size'));
        $min  = max(1, (int) $this->option('min-count'));

        $logsIndex     = config('elasticsearch.indices.search_logs.name');
        $productsIndex = config('elasticsearch.indices.products.name');
        $synCfg        = config("elasticsearch.synonyms.{$key}");

        if (! $synCfg) {
            $this->error("No synonyms configuration found for key: '{$key}'");

            return self::FAILURE;
        }

        if (! $es->existsIndex($logsIndex)) {
            $this->warn("Index '{$logsIndex}' does not exist yet. Bootstrap it with: php artisan elasticsearch:create-index search_logs");

            return self::FAILURE;
        }

        $buckets = array_values(array_filter(
            $this->zeroResultQueries($es, $logsIndex, $days, $size),
            fn ($b) => (int) $b['doc_count'] >= $min,
        ));

        if ($buckets === []) {
            $this->info("No zero-result queries in the last {$days} day(s) (min-count {$min}). Nothing to suggest.");

            return self::SUCCESS;
        }

        // Existing dictionary: folded term => rule id, plus id => raw synonyms line
        [$ruleOf, $lineOf] = $this->existingTerms($synCfg['rules']);

        $rows     = [];
        $augment  = []; // ruleId => [new terms to append]
        $newStubs = [];

        foreach ($buckets as $b) {
            $query  = (string) $b['key'];
            $count  = (int) $b['doc_count'];
            $folded = $this->fold($query);

            // Already in the dictionary (defensive — shouldn't be zero-result)
            if (isset($ruleOf[$folded])) {
                continue;
            }

            [$nearTerm, $nearRule] = $this->nearestSynonym($folded, $ruleOf);
            $catalog = $this->catalogCandidates($es, $productsIndex, $query);

            if ($nearRule !== null) {
                $rows[] = [$query, $count, "≈ '{$nearTerm}' → '{$nearRule}'", "rule '{$nearRule}'-ə əlavə"];
                $augment[$nearRule][] = $query;
            } elseif ($catalog !== []) {
                $rows[] = [$query, $count, implode(', ', $catalog), 'yeni rule (kataloq namizədi)'];
                $newStubs[] = $this->stub($query, $catalog);
            } else {
                $rows[] = [$query, $count, '—', 'yeni rule (əl ilə təyin et)'];
                $newStubs[] = $this->stub($query, []);
            }
        }

        if ($rows === []) {
            $this->info('All zero-result queries are already covered by the dictionary. Nothing to suggest.');

            return self::SUCCESS;
        }

        $this->info("Zero-result queries (last {$days}d, min-count {$min}) → synonym candidates:");
        $this->table(['Query', 'Count', 'Candidate', 'Action'], $rows);

        $this->newLine();
        $this->line("# config/elasticsearch.php → synonyms.{$key}.rules — review before pasting:");

        foreach ($augment as $ruleId => $terms) {
            $merged = $lineOf[$ruleId] . ', ' . implode(', ', array_unique($terms));
            $this->line("['id' => '{$ruleId}', 'synonyms' => '{$merged}'],");
        }
        foreach ($newStubs as $stub) {
            $this->line($stub);
        }

        $this->newLine();
        $this->comment("Apply: edit config → php artisan elasticsearch:synonyms {$key} (təsiri dərhal, reindex lazım deyil).");

        return self::SUCCESS;
    }

    /** Zero-result query terms with counts, newest-window, excluding empty browse queries. */
    private function zeroResultQueries(ElasticsearchService $es, string $index, int $days, int $size): array
    {
        $response = $es->search($index, [
            'size'  => 0,
            'query' => ['bool' => ['filter' => [
                ['range' => ['created_at' => ['gte' => now()->subDays($days)->format('Y-m-d H:i:s')]]],
                ['term'  => ['zero_results' => true]],
            ], 'must_not' => [['term' => ['query' => '']]]]],
            'aggs' => ['queries' => ['terms' => ['field' => 'query', 'size' => $size]]],
        ]);

        return $response['aggregations']['queries']['buckets'] ?? [];
    }

    /**
     * @return array{0: array<string,string>, 1: array<string,string>}
     *               [foldedTerm => ruleId, ruleId => rawSynonymsLine]
     */
    private function existingTerms(array $rules): array
    {
        $ruleOf = [];
        $lineOf = [];

        foreach ($rules as $rule) {
            $id = $rule['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $lineOf[$id] = $rule['synonyms'];

            foreach (explode(',', $rule['synonyms']) as $term) {
                $folded = $this->fold(trim($term));
                if ($folded !== '') {
                    $ruleOf[$folded] = $id;
                }
            }
        }

        return [$ruleOf, $lineOf];
    }

    /**
     * Closest existing synonym term within an edit-distance budget that scales
     * with word length (short words demand a tighter match to avoid noise).
     *
     * @return array{0: ?string, 1: ?string} [matchedFoldedTerm, ruleId]
     */
    private function nearestSynonym(string $folded, array $ruleOf): array
    {
        $maxDist = mb_strlen($folded) <= 4 ? 1 : 2;
        $best    = null;
        $bestDist = PHP_INT_MAX;

        foreach ($ruleOf as $term => $ruleId) {
            // Multi-word synonyms aren't useful single-term near-misses
            if (str_contains($term, ' ')) {
                continue;
            }
            $dist = levenshtein($folded, $term);
            if ($dist > 0 && $dist <= $maxDist && $dist < $bestDist) {
                $best = [$term, $ruleId];
                $bestDist = $dist;
            }
        }

        return $best ?? [null, null];
    }

    /** Loose fuzzy lookup against the catalog → top matching category + brand. */
    private function catalogCandidates(ElasticsearchService $es, string $index, string $query): array
    {
        $response = $es->search($index, [
            'size'  => 0,
            'query' => ['multi_match' => [
                'query'         => $query,
                'fields'        => ['category^2', 'brand^2', 'name.dym', 'name.az'],
                'fuzziness'     => 'AUTO',
                'prefix_length' => 1,
            ]],
            'aggs' => [
                'cat'   => ['terms' => ['field' => 'category.keyword', 'size' => 1]],
                'brand' => ['terms' => ['field' => 'brand.keyword', 'size' => 1]],
            ],
        ]);

        $out = [];
        foreach (['cat', 'brand'] as $agg) {
            $term = $response['aggregations'][$agg]['buckets'][0]['key'] ?? null;
            if ($term !== null && $term !== '') {
                $out[] = $term;
            }
        }

        return array_values(array_unique($out));
    }

    /** A paste-ready new rule line; '???' marks where the human must fill the target. */
    private function stub(string $query, array $candidates): string
    {
        $id    = Str::slug($query, '_') ?: 'rule';
        $terms = array_values(array_unique(array_filter(array_merge([$query], $candidates))));
        $line  = implode(', ', $terms) . ($candidates === [] ? ', ???' : '');

        return "['id' => '{$id}', 'synonyms' => '{$line}'],";
    }

    private function fold(string $value): string
    {
        return mb_strtolower(strtr($value, self::FOLD), 'UTF-8');
    }
}
