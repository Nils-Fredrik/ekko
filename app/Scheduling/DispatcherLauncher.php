<?php

namespace App\Scheduling;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Web can only wake this fixed poll dispatcher, without command or environment overrides. */
final class DispatcherLauncher
{
    public function __construct(private CloudRunAccessToken $tokens) {}

    /** Wake the fixed dispatcher after the queued job becomes visible to a separate worker. */
    public function startAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            try {
                $this->start();
            } catch (LockTimeoutException) {
                // A concurrent request is already waking the worker.
            } catch (Throwable $failure) {
                report($failure);
            }
        });
    }

    public function start(): bool
    {
        if (! config('collection.cloud_run.enabled')) {
            return false;
        }
        $project = config('collection.cloud_run.project');
        $region = config('collection.cloud_run.region');
        if (! is_string($project) || ! is_string($region) || ! preg_match('/^[a-z][a-z0-9-]+$/D', $project) || ! preg_match('/^[a-z]+-[a-z]+[0-9]$/D', $region)) {
            throw new RuntimeException('invalid_cloud_run_configuration');
        }

        return CollectionLocks::make('ekko:wake-launch', 180)->block(1, function () use ($project, $region): bool {
            $url = "https://run.googleapis.com/v2/projects/{$project}/locations/{$region}/jobs/ekko-wake";
            $client = Http::withToken($this->tokens->get())->acceptJson()->withoutRedirecting()->timeout(30);
            $deadline = microtime(true) + 90;
            $page = null;
            $seen = [];
            do {
                $active = $client->get($url.'/executions', array_filter(['pageSize' => 100, 'pageToken' => $page]));
                if (! $active->successful()) {
                    throw new RuntimeException('cloud_run_list_failed');
                }
                foreach ($active->json('executions', []) as $execution) {
                    if (empty($execution['completionTime'])) {
                        return false;
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('cloud_run_execution_list_timeout');
                }
                $page = $active->json('nextPageToken');
                if ($page && isset($seen[$page])) {
                    throw new RuntimeException('cloud_run_execution_list_incomplete');
                }
                if ($page) {
                    $seen[$page] = true;
                }
            } while ($page);
            if (! $client->withBody('{}', 'application/json')->post($url.':run')->successful()) {
                throw new RuntimeException('cloud_run_start_failed');
            }

            return true;
        });
    }
}
