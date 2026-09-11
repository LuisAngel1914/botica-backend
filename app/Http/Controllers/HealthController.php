<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Reports whether the application can serve requests and reach its database.
     *
     * This endpoint is intentionally public so the hosting platform or an external
     * monitor can use it. It never returns connection details or exception messages.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->select('select 1');

            return response()->json([
                'status' => 'ok',
                'checks' => [
                    'database' => 'ok',
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'degraded',
                'checks' => [
                    'database' => 'unavailable',
                ],
            ], 503);
        }
    }
}
