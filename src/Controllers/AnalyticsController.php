<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnalyticsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/analytics?type=month|year|fy&anchor=<period>&account_id=<id>
 *
 * `anchor` is 'YYYY-MM' for a month, 'YYYY' for a calendar year, and the
 * STARTING year for a financial year (2025 => FY 2025-26). Omit it for the
 * most recent period that has data — statements are historical, so anchoring
 * to the wall clock would usually show an empty month.
 */
final class AnalyticsController
{
    private const TYPES = ['month', 'year', 'fy'];

    public function __construct(private AnalyticsService $analytics)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $q      = $request->getQueryParams();
        $type   = in_array($q['type'] ?? '', self::TYPES, true) ? $q['type'] : 'month';
        $anchor = isset($q['anchor']) && $q['anchor'] !== '' ? (string) $q['anchor'] : null;
        $account = (int) ($q['account_id'] ?? 0) ?: null;

        $payload = $this->analytics->report($type, $anchor, $account);
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
