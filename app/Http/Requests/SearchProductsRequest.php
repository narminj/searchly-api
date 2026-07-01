<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and normalizes the product search request, keeping the controller
 * a thin orchestrator. Exposes the repository's three inputs as intent-revealing
 * accessors: searchQuery(), filters() and options().
 *
 * Tenant resolution deliberately stays in the controller — it is shared by every
 * product endpoint (search, show, suggest, click), not just this request.
 */
class SearchProductsRequest extends FormRequest
{
    /** ES rejects from + size > max_result_window; counting past it is wasted work. */
    private const MAX_RESULT_WINDOW = 10000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The page cap is derived from per_page (not a fixed number) so that
        // from + size can never exceed max_result_window.
        $perPage = min(100, max(1, (int) $this->input('per_page', 15)));
        $maxPage = max(1, intdiv(self::MAX_RESULT_WINDOW, $perPage));

        return [
            'q'            => 'nullable|string|max:200',
            'category'     => 'nullable|string|max:100',
            'categories'   => 'nullable|array|max:10',
            'categories.*' => 'string|max:100',
            'brand'        => 'nullable|string|max:100',
            'brands'       => 'nullable|array|max:10',
            'brands.*'     => 'string|max:100',
            'tags'         => 'nullable|array|max:10',
            'tags.*'       => 'string|max:50',
            'price_min'    => 'nullable|numeric|min:0',
            'price_max'    => 'nullable|numeric|min:0',
            'in_stock'     => 'nullable|boolean',
            'sort'         => 'nullable|in:relevance,price_asc,price_desc,newest,oldest,name,stock_desc',
            'page'         => 'nullable|integer|min:1|max:' . $maxPage,
            'per_page'     => 'nullable|integer|min:1|max:100',
            'from_date'    => 'nullable|date_format:Y-m-d',
            'to_date'      => 'nullable|date_format:Y-m-d',
            'geo_lat'      => 'nullable|numeric|between:-90,90',
            'geo_lon'      => 'nullable|numeric|between:-180,180',
            'geo_distance' => 'nullable|string|regex:/^\d+(\.\d+)?(km|m|mi|yd)$/',
            // Aggregations are the most expensive part of a search — clients
            // should send with_aggs=0 beyond page 1.
            'with_aggs'    => 'nullable|boolean',
            // Opaque next_cursor from a previous response (search_after);
            // when present, page/from are ignored downstream.
            'cursor'       => 'nullable|string|max:512',
        ];
    }

    /**
     * Reject a cursor that doesn't decode to a small flat array of scalars,
     * as a 422, before it can ever reach Elasticsearch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cursor = $this->input('cursor');

            if (is_string($cursor) && $cursor !== '' && $this->decodeCursor($cursor) === null) {
                $validator->errors()->add('cursor', 'Invalid cursor.');
            }
        });
    }

    /** Free-text query; empty string means match-all. */
    public function searchQuery(): string
    {
        return $this->validated('q') ?? '';
    }

    /** 1-based page number (default 1). */
    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    /**
     * Result-narrowing filters. Only keys the client actually sent are kept, so
     * legitimately falsy values (price_min=0, in_stock=false) are preserved
     * while absent params are dropped.
     */
    public function filters(): array
    {
        $v = $this->validated();

        return array_filter([
            'category'   => $v['category']   ?? null,
            'categories' => $v['categories'] ?? null,
            'brand'      => $v['brand']      ?? null,
            'brands'     => $v['brands']     ?? null,
            'tags'       => $v['tags']       ?? null,
            'price_min'  => $v['price_min']  ?? null,
            'price_max'  => $v['price_max']  ?? null,
            'in_stock'   => $v['in_stock']   ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Presentation + pagination options. The cursor is decoded here (already
     * validated as decodable above). Tenant is injected by the controller.
     */
    public function options(): array
    {
        $v = $this->validated();

        return array_filter([
            'sort'         => $v['sort']         ?? null,
            'page'         => $v['page']         ?? null,
            'per_page'     => $v['per_page']     ?? null,
            'from_date'    => $v['from_date']    ?? null,
            'to_date'      => $v['to_date']      ?? null,
            'geo_lat'      => $v['geo_lat']      ?? null,
            'geo_lon'      => $v['geo_lon']      ?? null,
            'geo_distance' => $v['geo_distance'] ?? null,
            'with_aggs'    => array_key_exists('with_aggs', $v) ? (bool) $v['with_aggs'] : null,
            'cursor'       => isset($v['cursor']) && $v['cursor'] !== '' ? $this->decodeCursor($v['cursor']) : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Cursors are base64(json(sort values of the last hit)) that we produced in
     * next_cursor. Accept only a small flat array of scalars; anything else is
     * rejected as null.
     */
    private function decodeCursor(string $cursor): ?array
    {
        $decoded = json_decode(base64_decode($cursor, true) ?: '', true);

        if (! is_array($decoded) || $decoded === [] || count($decoded) > 4) {
            return null;
        }

        foreach ($decoded as $value) {
            if (! is_scalar($value) && $value !== null) {
                return null;
            }
        }

        return array_values($decoded);
    }
}
