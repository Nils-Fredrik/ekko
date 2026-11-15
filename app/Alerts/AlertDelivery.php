<?php

namespace App\Alerts;

use App\Mail\AlertDigestMail;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use App\Tenancy\Rls\TenantRls;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers the alerts of a tenant's local day as one message per channel (docs/spec.md 11.1, ADR 0019).
 *
 * The first run of the day gives the alerts that have no digest date the day's date ({@see assignDigest()}):
 * those are the alerts of the digest, and alerts created later in the day wait for the next one. Later runs of
 * the day only retry the channels that failed. The channels of an alert are those of its rule: in_app is the
 * alert list itself and needs no delivery, slack posts to the tenant's webhook, email goes to the recipients
 * (AlertRecipients). A rule that is turned off delivers nothing.
 *
 * The receipt of every channel is kept on the alert (deliveries: status, attempts, time, and for e-mail the
 * users already sent to), so a retry never sends to the same person twice. A channel that has failed
 * ekko.alerts.delivery_attempts times is given up, and so is a channel still failing when its day is over. A
 * channel with nobody to send to (no webhook, no recipient) is skipped. The alert is delivered (delivered_at)
 * when no channel is waiting any more.
 *
 * The messages are sent outside a database transaction, and every receipt is saved in its own transaction
 * straight after the send, so a later failure cannot roll back a receipt and make the next run send the same
 * message again. A tenant lock keeps two runs from sending the same alerts at once. Every database phase sets
 * row-level security itself, so the job using this class does not run in a transaction (the job overrides
 * middleware(), like CollectEngineRun).
 */
final class AlertDelivery
{
    private const FINAL = ['sent', 'skipped', 'gave_up'];

    /** Namespace of the advisory lock, with the tenant id as the second key. */
    private const LOCK = 24_001;

    public function __construct(
        private readonly AlertPresenter $presenter,
        private readonly AlertRecipients $recipients,
        private readonly SlackNotifier $slack,
        private readonly TenantRls $rls,
    ) {}

    /**
     * The alerts without a digest date that are recent enough become the digest of the day. Older ones stay in
     * the list only: after downtime, or the first run after the alerts were introduced, a flood of old messages
     * would be worse than none. Call inside the tenant's row-level security.
     */
    public function assignDigest(CarbonImmutable $now, string $digestDate): int
    {
        return Alert::query()->whereNull('digest_date')
            ->where('triggered_at', '>', $now->utc()->subDays((int) config('ekko.alerts.digest_max_age_days')))
            ->update(['digest_date' => $digestDate]);
    }

    /**
     * Urgent alerts (payload urgent, the quota alert at 100 %) do not wait for the digest: whenever the job runs,
     * those without a digest date get the tenant's local date and go out with the next delivery, also before the
     * day's evaluation and after the day's digest was sent. Call inside the tenant's row-level security.
     */
    public function assignUrgent(string $digestDate): int
    {
        return Alert::query()->whereNull('digest_date')
            ->where('payload->urgent', true)
            ->update(['digest_date' => $digestDate]);
    }

    public function deliver(CarbonImmutable $now, string $digestDate): void
    {
        $tenantId = current_tenant_id();
        if (! $this->lock($tenantId)) {
            return;
        }

        try {
            $this->run($tenantId, $now, $digestDate);
        } finally {
            DB::select('select pg_advisory_unlock(?, ?)', [self::LOCK, $tenantId]);
        }
    }

    private function lock(int $tenantId): bool
    {
        return (bool) DB::selectOne('select pg_try_advisory_lock(?, ?) as locked', [self::LOCK, $tenantId])->locked;
    }

    private function run(int $tenantId, CarbonImmutable $now, string $digestDate): void
    {
        // Phase 1: read what to send and build the messages. The skipped channels are saved here.
        $plan = $this->rls->run([$tenantId], fn (): ?array => $this->plan($tenantId, $now, $digestDate));
        if ($plan === null) {
            return;
        }

        // Phase 2: send, and save each receipt at once.
        if ($plan['slack'] !== null) {
            $sent = $this->slack->send($plan['slack']['webhook'], $plan['slack']['text']);
            $this->persist($tenantId, $plan['slack']['alerts'], fn (Alert $alert) => $sent
                ? $this->record($alert, 'slack', 'sent', $now)
                : $this->recordFailure($alert, 'slack', $now));
        }

        $failed = [];
        foreach ($plan['mail'] as $mail) {
            try {
                Mail::to($mail['user'])->locale($mail['locale'])->send(new AlertDigestMail(
                    $mail['tenant'], $mail['groups'], count($mail['alerts']), url('/dashboard'),
                ));
            } catch (Throwable $failure) {
                report($failure);
                foreach ($mail['alerts'] as $id) {
                    $failed[$id] = true;
                }

                continue;
            }

            $this->persist($tenantId, $mail['alerts'], function (Alert $alert) use ($mail): void {
                $deliveries = $alert->deliveries ?? [];
                $deliveries['email']['sent_to'] = [...(array) ($deliveries['email']['sent_to'] ?? []), $mail['user']->id];
                $alert->deliveries = $deliveries;
            });
        }

        // Phase 3: the e-mail receipts of the alerts, and which alerts are delivered now.
        $this->persist($tenantId, $plan['alerts'], function (Alert $alert) use ($plan, $failed, $now): void {
            if (in_array($alert->id, $plan['mailAlerts'], true)) {
                isset($failed[$alert->id]) ? $this->recordFailure($alert, 'email', $now) : $this->record($alert, 'email', 'sent', $now);
            }
            $this->settle($alert, $now);
        });
    }

    /**
     * @return array{alerts: list<int>, mailAlerts: list<int>, slack: array{webhook: string, text: string, alerts: list<int>}|null, mail: list<array{user: User, locale: string, tenant: string, groups: list<array{title: string, lines: list<string>}>, alerts: list<int>}>}|null
     */
    private function plan(int $tenantId, CarbonImmutable $now, string $digestDate): ?array
    {
        // A channel that still fails when its day is over is not tried again: the digest of that day is past.
        $over = Alert::query()->with('alertRule')->whereNotNull('digest_date')->where('digest_date', '<', $digestDate)->whereNull('delivered_at')->get();
        foreach ($over as $alert) {
            foreach ($this->channels($alert) as $channel) {
                if (! $this->isFinal($alert, $channel)) {
                    $this->record($alert, $channel, 'gave_up', $now);
                }
            }
            $alert->delivered_at = $now->utc();
            $alert->save();
        }

        $alerts = Alert::query()->with('alertRule.brand')
            ->where('digest_date', $digestDate)->whereNull('delivered_at')
            ->orderBy('triggered_at')->orderBy('id')->get();
        if ($alerts->isEmpty()) {
            return null;
        }

        $tenant = Team::query()->findOrFail($tenantId);
        $locale = $this->locale($tenant->default_locale, $tenant->default_locale);

        $slack = $this->slackPlan($tenant, $alerts, $locale, $now);
        [$mail, $mailAlerts] = $this->mailPlan($tenant, $alerts, $locale, $now);

        foreach ($alerts as $alert) {
            $alert->save();
        }

        return [
            'alerts' => $alerts->pluck('id')->all(),
            'mailAlerts' => $mailAlerts,
            'slack' => $slack,
            'mail' => $mail,
        ];
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     * @return array{webhook: string, text: string, alerts: list<int>}|null
     */
    private function slackPlan(Team $tenant, Collection $alerts, string $locale, CarbonImmutable $now): ?array
    {
        $pending = $alerts->filter(fn (Alert $alert): bool => $this->waitsFor($alert, 'slack'));
        if ($pending->isEmpty()) {
            return null;
        }

        $webhook = $tenant->slack_webhook_url;
        if (! is_string($webhook) || ! $this->slack->accepts($webhook)) {
            $pending->each(fn (Alert $alert) => $this->record($alert, 'slack', 'skipped', $now));

            return null;
        }

        $text = trans_choice('alerts.slack.heading', $pending->count(), ['tenant' => SlackNotifier::escape($tenant->name), 'count' => $pending->count()], $locale);
        foreach ($this->presenter->groups($pending, $locale) as $group) {
            $text .= "\n\n*".SlackNotifier::escape($group['title'])."*\n".implode("\n", array_map(fn (string $line): string => '• '.SlackNotifier::escape($line), $group['lines']));
        }

        return ['webhook' => $webhook, 'text' => $text, 'alerts' => $pending->pluck('id')->all()];
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     * @return array{0: list<array{user: User, locale: string, tenant: string, groups: list<array{title: string, lines: list<string>}>, alerts: list<int>}>, 1: list<int>}
     */
    private function mailPlan(Team $tenant, Collection $alerts, string $tenantLocale, CarbonImmutable $now): array
    {
        /** @var array<int, array{user: User, alerts: list<Alert>}> $perUser */
        $perUser = [];
        $waiting = [];

        foreach ($alerts->filter(fn (Alert $alert): bool => $this->waitsFor($alert, 'email')) as $alert) {
            $recipients = $this->recipients->forAlert($alert, $tenant);
            if ($recipients->isEmpty()) {
                $this->record($alert, 'email', 'skipped', $now);

                continue;
            }

            $waiting[] = $alert->id;
            $sentTo = (array) ($alert->deliveries['email']['sent_to'] ?? []);
            foreach ($recipients->reject(fn (User $user): bool => in_array($user->id, $sentTo, true)) as $user) {
                $perUser[$user->id]['user'] = $user;
                $perUser[$user->id]['alerts'][] = $alert;
            }
        }

        $mail = [];
        foreach ($perUser as $entry) {
            $locale = $this->locale($entry['user']->locale, $tenantLocale);
            $mail[] = [
                'user' => $entry['user'],
                'locale' => $locale,
                'tenant' => $tenant->name,
                'groups' => $this->presenter->groups($entry['alerts'], $locale),
                'alerts' => array_map(fn (Alert $alert): int => $alert->id, $entry['alerts']),
            ];
        }

        return [$mail, $waiting];
    }

    /**
     * @param  list<int>  $ids
     * @param  Closure(Alert): mixed  $change
     */
    private function persist(int $tenantId, array $ids, Closure $change): void
    {
        $this->rls->run([$tenantId], function () use ($ids, $change): void {
            foreach (Alert::query()->with('alertRule')->whereIn('id', $ids)->get() as $alert) {
                $change($alert);
                $alert->save();
            }
        });
    }

    private function settle(Alert $alert, CarbonImmutable $now): void
    {
        $waiting = array_filter($this->channels($alert), fn (string $channel): bool => ! $this->isFinal($alert, $channel));
        if ($waiting === []) {
            $alert->delivered_at = $now->utc();
        }
    }

    private function locale(?string $locale, string $fallback): string
    {
        return in_array($locale, config('ekko.locales'), true) ? (string) $locale : (in_array($fallback, config('ekko.locales'), true) ? $fallback : 'nb');
    }

    /**
     * The channels of the alert's rule that need a delivery. A rule that is turned off delivers nothing, also
     * for alerts that other parts of the system create (quota, integration_error).
     *
     * @return list<string>
     */
    private function channels(Alert $alert): array
    {
        if (! $alert->alertRule->is_active) {
            return [];
        }

        return array_values(array_intersect(['slack', 'email'], (array) $alert->alertRule->channels));
    }

    private function waitsFor(Alert $alert, string $channel): bool
    {
        return in_array($channel, $this->channels($alert), true) && ! $this->isFinal($alert, $channel);
    }

    private function isFinal(Alert $alert, string $channel): bool
    {
        return in_array($alert->deliveries[$channel]['status'] ?? null, self::FINAL, true);
    }

    private function record(Alert $alert, string $channel, string $status, CarbonImmutable $now): void
    {
        $deliveries = $alert->deliveries ?? [];
        $current = $deliveries[$channel] ?? [];
        $deliveries[$channel] = [
            ...$current,
            'status' => $status,
            'attempts' => (int) ($current['attempts'] ?? 0) + ($status === 'sent' ? 1 : 0),
            'at' => $now->utc()->toIso8601String(),
        ];
        $alert->deliveries = $deliveries;
    }

    private function recordFailure(Alert $alert, string $channel, CarbonImmutable $now): void
    {
        $attempts = (int) ($alert->deliveries[$channel]['attempts'] ?? 0) + 1;
        $deliveries = $alert->deliveries ?? [];
        $deliveries[$channel] = [
            ...($deliveries[$channel] ?? []),
            'status' => $attempts >= (int) config('ekko.alerts.delivery_attempts') ? 'gave_up' : 'failed',
            'attempts' => $attempts,
            'at' => $now->utc()->toIso8601String(),
        ];
        $alert->deliveries = $deliveries;
    }
}
