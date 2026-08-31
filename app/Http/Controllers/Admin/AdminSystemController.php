<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Traits\LogsAdminAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AdminSystemController extends ApiController
{
    use LogsAdminAction;

    public function health(): JsonResponse
    {
        $health = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => [
                'failed_jobs' => DB::getSchemaBuilder()->hasTable('failed_jobs')
                    ? (int) DB::table('failed_jobs')->count()
                    : 0,
            ],
            'disk' => [
                'free_mb' => round(disk_free_space(base_path()) / 1024 / 1024, 2),
            ],
            'app' => [
                'env' => config('app.env'),
                'debug' => config('app.debug'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ];

        return $this->sendResponse($health, 'System health retrieved');
    }

    public function logs(Request $request): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');

        if (! File::exists($logPath)) {
            return $this->sendResponse(['lines' => [], 'total' => 0], 'No logs found');
        }

        $lines = collect(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->reverse()
            ->take($request->integer('limit', 100))
            ->values()
            ->map(fn ($line) => $this->sanitizeLogLine($line));

        return $this->sendResponse(['lines' => $lines, 'total' => $lines->count()], 'Logs retrieved');
    }

    public function config(): JsonResponse
    {
        return $this->sendResponse([
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'mail_mailer' => config('mail.default'),
            'queue_connection' => config('queue.default'),
            'cache_driver' => config('cache.default'),
        ], 'Config snapshot retrieved');
    }

    public function clearCache(Request $request): JsonResponse
    {
        Artisan::call('cache:clear');
        $this->logAdminAction($request, 'system.cache.clear');

        return $this->sendResponse(null, 'Cache cleared');
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Database connection failed'];
        }
    }

    private function checkCache(): array
    {
        try {
            Cache::put('admin_health_check', true, 10);
            $ok = Cache::get('admin_health_check') === true;

            return ['status' => $ok ? 'ok' : 'error'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Cache check failed'];
        }
    }

    private function sanitizeLogLine(string $line): string
    {
        return preg_replace('/password[=:]\S+/i', 'password=***', $line) ?? $line;
    }
}
