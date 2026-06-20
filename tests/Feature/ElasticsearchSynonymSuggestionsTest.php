<?php

namespace Tests\Feature;

use App\Services\ElasticsearchService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ElasticsearchSynonymSuggestionsTest extends TestCase
{
    private MockInterface $es;

    protected function setUp(): void
    {
        parent::setUp();

        $this->es = Mockery::mock(ElasticsearchService::class);
        $this->app->instance(ElasticsearchService::class, $this->es);

        // Catalog lookups (Tier 2) default to "no match" so the tests assert
        // Tier 1 / manual behaviour deterministically; individual tests can
        // still rely on this returning empty aggregations.
        $this->es->shouldReceive('search')
            ->with('products_test', Mockery::any())
            ->andReturn([])
            ->zeroOrMoreTimes();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Mock the zero-result aggregation the command reads from search_logs. */
    private function fakeZeroResults(array $queryCounts): void
    {
        $buckets = [];
        foreach ($queryCounts as $query => $count) {
            $buckets[] = ['key' => $query, 'doc_count' => $count];
        }

        $this->es->shouldReceive('existsIndex')->with('search_logs')->andReturn(true);
        $this->es->shouldReceive('search')
            ->with('search_logs', Mockery::any())
            ->andReturn(['aggregations' => ['queries' => ['buckets' => $buckets]]]);
    }

    public function test_near_miss_of_existing_term_is_routed_to_that_rule(): void
    {
        // 'velosped' ≈ 'velosiped' (rule bicycle), 'fotoaprat' ≈ 'fotoaparat' (rule camera)
        $this->fakeZeroResults(['velosped' => 3, 'fotoaprat' => 2]);

        $this->artisan('elasticsearch:synonym-suggestions')
            ->expectsOutputToContain("rule 'bicycle'")
            ->expectsOutputToContain("rule 'camera'")
            // paste-ready lines merge the new variant into the existing rule
            ->expectsOutputToContain("'id' => 'bicycle', 'synonyms' => 'velosiped, bicycle, bike, velosped'")
            ->expectsOutputToContain("'id' => 'camera', 'synonyms' => 'kamera, camera, fotoaparat, fotoaprat'")
            ->assertExitCode(0);
    }

    public function test_unknown_term_becomes_a_new_rule_stub(): void
    {
        $this->fakeZeroResults(['totallynewterm' => 1]);

        $this->artisan('elasticsearch:synonym-suggestions')
            ->expectsOutputToContain("'id' => 'totallynewterm', 'synonyms' => 'totallynewterm, ???'")
            ->assertExitCode(0);
    }

    public function test_terms_already_in_dictionary_are_skipped(): void
    {
        // 'telefon' is already a synonym in the 'phone' rule → not a candidate
        $this->fakeZeroResults(['telefon' => 5]);

        $this->artisan('elasticsearch:synonym-suggestions')
            ->expectsOutputToContain('already covered')
            ->assertExitCode(0);
    }

    public function test_min_count_filters_low_volume_noise(): void
    {
        $this->fakeZeroResults(['velosped' => 1]);

        // velosped seen once; --min-count=2 drops it → nothing to suggest
        $this->artisan('elasticsearch:synonym-suggestions', ['--min-count' => 2])
            ->expectsOutputToContain('Nothing to suggest')
            ->assertExitCode(0);
    }

    public function test_fails_when_logs_index_missing(): void
    {
        $this->es->shouldReceive('existsIndex')->with('search_logs')->andReturn(false);

        $this->artisan('elasticsearch:synonym-suggestions')
            ->expectsOutputToContain('does not exist yet')
            ->assertExitCode(1);
    }

    public function test_fails_for_unknown_synonyms_key(): void
    {
        $this->artisan('elasticsearch:synonym-suggestions', ['key' => 'no_such_key'])
            ->expectsOutputToContain('No synonyms configuration found')
            ->assertExitCode(1);
    }
}
