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

        $limit = max(10, min(1000, $request->integer('limit', 200)));
        $level = strtoupper((string) $request->input('level'));
        $search = (string) $request->input('search');

        // Read from the end of the file in blocks instead of loading it whole.
        $lines = [];
        $handle = fopen($logPath, 'rb');
        $pos = filesize($logPath);
        $buffer = '';
        $scanned = 0;

        while ($pos > 0 && count($lines) < $limit && $scanned < 20 * 1024 * 1024) {
            $read = min(65536, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read) . $buffer;
            $scanned += $read;
            $parts = explode("\n", $buffer);
            $buffer = array_shift($parts);

            foreach (array_reverse($parts) as $line) {
                if ($line === '' || ! preg_match('/^\[\d{4}-\d{2}-\d{2}/', $line)) {
                    continue;
                }
                if ($level !== '' && ! str_contains($line, ".{$level}:")) {
                    continue;
                }
                if ($search !== '' && stripos($line, $search) === false) {
                    continue;
                }
                $lines[] = $this->sanitizeLogLine(mb_substr($line, 0, 2000));
                if (count($lines) >= $limit) {
                    break;
                }
            }
        }
        fclose($handle);

        return $this->sendResponse([
            'lines' => $lines,
            'total' => count($lines),
            'size_mb' => round(filesize($logPath) / 1024 / 1024, 2),
        ], 'Logs retrieved');
    }

    public function failedJobs(Request $request): JsonResponse
    {
        if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            return $this->sendResponse(['data' => [], 'total' => 0], 'No failed jobs table');
        }

        $page = DB::table('failed_jobs')->orderByDesc('failed_at')
            ->paginate(min(100, max(5, $request->integer('per_page', 25))), ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        $page->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true) ?: [];

            return [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'connection' => $job->connection,
                'job' => $payload['displayName'] ?? ($payload['job'] ?? 'Unknown job'),
                'attempts' => $payload['attempts'] ?? null,
                'error' => mb_substr(strtok($job->exception, "\n") ?: '', 0, 500),
                'exception' => mb_substr($job->exception, 0, 4000),
                'failed_at' => $job->failed_at,
            ];
        });

        return $this->sendResponse($page, 'Failed jobs retrieved');
    }

    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        $target = $uuid === 'all' ? 'all' : $uuid;
        Artisan::call('queue:retry', ['id' => [$target]]);
        $this->logAdminAction($request, 'system.jobs.retry', 'failed_job', null, null, ['uuid' => $target]);

        return $this->sendResponse(null, $target === 'all' ? 'All failed jobs queued again' : 'Job queued again');
    }

    public function forgetFailedJob(Request $request, string $uuid): JsonResponse
    {
        if ($uuid === 'all') {
            Artisan::call('queue:flush');
        } else {
            Artisan::call('queue:forget', ['id' => $uuid]);
        }
        $this->logAdminAction($request, 'system.jobs.delete', 'failed_job', null, null, ['uuid' => $uuid]);

        return $this->sendResponse(null, $uuid === 'all' ? 'All failed jobs deleted' : 'Failed job deleted');
    }

    /** Pending jobs per queue and the scheduled commands with their next run. */
    public function queue(): JsonResponse
    {
        $pending = DB::getSchemaBuilder()->hasTable('jobs')
            ? DB::table('jobs')->selectRaw('queue, count(*) as pending, min(created_at) as oldest')->groupBy('queue')->get()
                ->map(fn ($r) => ['queue' => $r->queue, 'pending' => (int) $r->pending, 'oldest' => $r->oldest ? date(DATE_ATOM, (int) $r->oldest) : null])
            : collect();

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $tasks = collect($schedule->events())->map(function ($event) {
            $command = preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command);

            return [
                'command' => $command ?: ($event->description ?? 'Closure'),
                'expression' => $event->expression,
                'next_run' => $event->nextRunDate()->format(DATE_ATOM),
                'timezone' => (string) ($event->timezone ?: config('app.timezone')),
            ];
        })->values();

        return $this->sendResponse([
            'connection' => config('queue.default'),
            'queues' => $pending,
            'scheduled' => $tasks,
        ], 'Queue status retrieved');
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
            'session_driver' => config('session.driver'),
            'filesystem' => config('filesystems.default'),
            'broadcast_driver' => config('broadcasting.default'),
            'log_channel' => config('logging.default'),
            'debug' => (bool) config('app.debug'),
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
