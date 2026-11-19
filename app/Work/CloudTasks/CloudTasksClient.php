<?php

namespace App\Work\CloudTasks;

use App\Scheduling\CloudRunAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates HTTP tasks in the work queue (T40-F4, ADR 0033). Every task goes to one of the fixed internal routes on
 * app.url with a Google OIDC token of the configured service account and audience, so the receiver can verify who
 * sent it. The body is JSON of internal ids only.
 *
 * Creating is idempotent: the task id is deterministic, and ALREADY_EXISTS (HTTP 409) means that this wake-up, or
 * one with the same id, is already out. A transient failure (connection, 429, 5xx) is tried again up to
 * publish_attempts times; a create that succeeded although its answer was lost then comes back as ALREADY_EXISTS.
 * Anything else throws: the work is durable, and the recovery check publishes it again.
 */
class CloudTasksClient
{
    /** The only paths a task may target. */
    public const PATHS = ['/internal/work/wake', '/internal/work/queue-wake'];

    public function __construct(private readonly CloudRunAccessToken $tokens) {}

    /**
     * @param  array<string, int|string>  $body
     * @return bool true when created, false when it already existed
     */
    public function create(string $taskId, string $path, array $body, CarbonImmutable $at): bool
    {
        if (! in_array($path, self::PATHS, true) || ! preg_match('/^[A-Za-z0-9_-]{1,500}$/D', $taskId)) {
            throw new RuntimeException('cloud_tasks_invalid_task');
        }

        $config = $this->config();
        $queue = "projects/{$config['project']}/locations/{$config['location']}/queues/{$config['queue']}";
        $task = [
            'name' => "{$queue}/tasks/{$taskId}",
            'dispatchDeadline' => max(15, (int) config('ekko.work.cloud_tasks.dispatch_deadline_seconds')).'s',
            'httpRequest' => [
                'httpMethod' => 'POST',
                'url' => $config['base_url'].$path,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => base64_encode((string) json_encode($body, JSON_THROW_ON_ERROR)),
                'oidcToken' => ['serviceAccountEmail' => $config['service_account'], 'audience' => $config['audience']],
            ],
        ];
        if ($at->isFuture()) {
            $task['scheduleTime'] = $at->utc()->format('Y-m-d\TH:i:s.v\Z');
        }

        $attempts = max(1, (int) config('ekko.work.cloud_tasks.publish_attempts'));
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withToken($this->tokens->get())->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(10)
                    ->post("https://cloudtasks.googleapis.com/v2/{$queue}/tasks", ['task' => $task]);
            } catch (ConnectionException $failure) {
                if ($attempt >= $attempts) {
                    throw new RuntimeException('cloud_tasks_unreachable', previous: $failure);
                }
                usleep(200_000 * $attempt);

                continue;
            }

            if ($response->successful()) {
                return true;
            }
            if ($this->alreadyExists($response)) {
                return false;
            }
            if (! $this->transient($response) || $attempt >= $attempts) {
                throw new RuntimeException('cloud_tasks_create_failed_'.$response->status());
            }
            usleep(200_000 * $attempt);
        }
    }

    private function alreadyExists(Response $response): bool
    {
        return $response->status() === 409 || $response->json('error.status') === 'ALREADY_EXISTS';
    }

    private function transient(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    /** @return array{project: string, location: string, queue: string, service_account: string, audience: string, base_url: string} */
    private function config(): array
    {
        $project = config('ekko.work.cloud_tasks.project');
        $location = config('ekko.work.cloud_tasks.location');
        $queue = config('ekko.work.cloud_tasks.queue');
        $account = config('ekko.work.cloud_tasks.service_account');
        $audience = config('ekko.work.cloud_tasks.audience');
        $base = config('app.url');

        if (! is_string($project) || ! preg_match('/^[a-z][a-z0-9-]+$/D', $project)
            || ! is_string($location) || ! preg_match('/^[a-z]+-[a-z]+[0-9]$/D', $location)
            || ! is_string($queue) || ! preg_match('/^[A-Za-z0-9-]{1,100}$/D', $queue)
            || ! is_string($account) || ! preg_match('/^[a-z0-9-]+@[a-z0-9-]+\.iam\.gserviceaccount\.com$/D', $account)
            || ! is_string($audience) || ! str_starts_with($audience, 'https://')
            || ! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw new RuntimeException('cloud_tasks_invalid_configuration');
        }

        return ['project' => $project, 'location' => $location, 'queue' => $queue, 'service_account' => $account, 'audience' => $audience, 'base_url' => rtrim($base, '/')];
    }
}
