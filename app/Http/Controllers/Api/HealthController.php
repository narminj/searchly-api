<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ElasticsearchService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    /**
     * GET /api/health
     *
     * Liveness/readiness probe. Reports API status and Elasticsearch cluster
     * health. Returns 503 when ES is unreachable or red so that load balancers
     * and uptime monitors can react.
     */
    #[OA\Get(
        path: '/health',
        summary: 'Health check',
        description: 'Reports API status and Elasticsearch cluster health (green/yellow/red).',
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'Healthy — ES green or yellow'),
            new OA\Response(response: 503, description: 'Degraded — ES unreachable or red'),
        ]
    )]
    public function __invoke(ElasticsearchService $es): JsonResponse
    {
        $health        = $es->clusterHealth();
        $clusterStatus = $health['status'] ?? null;            // green|yellow|red|null
        $esOk          = in_array($clusterStatus, ['green', 'yellow'], true);

        return response()->json([
            'status'        => $esOk ? 'ok' : 'degraded',
            'elasticsearch' => [
                'reachable' => $health !== [],
                'status'    => $clusterStatus,
                'nodes'     => $health['number_of_nodes'] ?? null,
            ],
        ], $esOk ? 200 : 503);
    }
}
