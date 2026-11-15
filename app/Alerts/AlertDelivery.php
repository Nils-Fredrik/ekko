<?php

namespace App\Alerts;

use App\Mail\AlertDigestMail;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers the alerts of a tenant's local day as one message per channel (docs/spec.md 11.1, ADR 0017).
 *
 * The first run of the day gives every alert that has no digest date the day's date: those are the alerts of
 * the digest, and alerts created later in the day wait for the next one. Later runs of the day only retry the
 * channels that failed. The channels of an alert are those of its rule: in_app is the alert list itself and
 * needs no delivery, slack posts to the tenant's webhook, email goes to the recipients (AlertRecipients).
 *
 * The receipt of every channel is kept on the alert (deliveries: status, attempts, time, and for e-mail the
 * users already sent to), so a retry never sends to the same person twice. A channel that has failed
 * ekko.alerts.delivery_attempts times is given up, and a channel with nobody to send to (no webhook, no
 * recipient) is skipped. The alert is delivered (delivered_at) when no channel is waiting any more.
 */
final class AlertDelivery
{
    private const FINAL = ['sent', 'skipped', 'gave_up'];

    public function __construct(
        private readonly AlertPresenter $presenter,
        private readonly AlertRecipients $recipients,
        private readonly SlackNotifier $slack,
    ) {}

    public function deliver(CarbonImmutable $now, string $digestDate, bool $assignNew): void
    {
        if ($assignNew) {
            Alert::query()->whereNull('digest_date')->update(['digest_date' => $digestDate]);
        }

        $alerts = Alert::query()->with('alertRule.brand')
            ->where('digest_date', $digestDate)->whereNull('delivered_at')
            ->orderBy('triggered_at')->orderBy('id')->get();
        if ($alerts->isEmpty()) {
            return;
        }

        $tenant = Team::query()->findOrFail(current_tenant_id());

        $this->toSlack($tenant, $alerts, $now);
        $this->toEmail($tenant, $alerts, $now);

        foreach ($alerts as $alert) {
            $waiting = array_filter($this->channels($alert), fn (string $channel): bool => ! $this->isFinal($alert, $channel));
            if ($waiting === []) {
                $alert->delivered_at = $now->utc();
            }
            $alert->save();
        }
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     */
    private function toSlack(Team $tenant, Collection $alerts, CarbonImmutable $now): void
    {
        $pending = $alerts->filter(fn (Alert $alert): bool => $this->waitsFor($alert, 'slack'));
        if ($pending->isEmpty()) {
            return;
        }

        $webhook = $tenant->slack_webhook_url;
        if (! is_string($webhook) || ! $this->slack->accepts($webhook)) {
            $pending->each(fn (Alert $alert) => $this->record($alert, 'slack', 'skipped', $now));

            return;
        }

        $groups = $this->presenter->groups($pending, $tenant->default_locale);
        $text = trans_choice('alerts.slack.heading', $pending->count(), ['tenant' => SlackNotifier::escape($tenant->name), 'count' => $pending->count()], $tenant->default_locale);
        foreach ($groups as $group) {
            $text .= "\n\n*".SlackNotifier::escape($group['title'])."*\n".implode("\n", array_map(fn (string $line): string => '• '.SlackNotifier::escape($line), $group['lines']));
        }

        $sent = $this->slack->send($webhook, $text);
        foreach ($pending as $alert) {
            $sent ? $this->record($alert, 'slack', 'sent', $now) : $this->recordFailure($alert, 'slack', $now);
        }
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     */
    private function toEmail(Team $tenant, Collection $alerts, CarbonImmutable $now): void
    {
        /** @var array<int, array{user: User, alerts: list<Alert>}> $perUser */
        $perUser = [];
        /** @var array<int, true> $failed */
        $failed = [];
        /** @var array<int, true> $skipped */
        $skipped = [];
        $pending = $alerts->filter(fn (Alert $alert): bool => $this->waitsFor($alert, 'email'));

        foreach ($pending as $alert) {
            $recipients = $this->recipients->forAlert($alert, $tenant);
            if ($recipients->isEmpty()) {
                $this->record($alert, 'email', 'skipped', $now);
                $skipped[$alert->id] = true;

                continue;
            }

            $sentTo = (array) ($alert->deliveries['email']['sent_to'] ?? []);
            foreach ($recipients->reject(fn (User $user): bool => in_array($user->id, $sentTo, true)) as $user) {
                $perUser[$user->id]['user'] = $user;
                $perUser[$user->id]['alerts'][] = $alert;
            }
        }

        foreach ($perUser as $entry) {
            $user = $entry['user'];
            $locale = in_array($user->locale, config('ekko.locales'), true) ? $user->locale : $tenant->default_locale;

            try {
                Mail::to($user)->locale($locale)->send(new AlertDigestMail(
                    $tenant->name,
                    $this->presenter->groups($entry['alerts'], $locale),
                    count($entry['alerts']),
                    url('/dashboard'),
                ));
            } catch (Throwable $failure) {
                report($failure);
                foreach ($entry['alerts'] as $alert) {
                    $failed[$alert->id] = true;
                }

                continue;
            }

            foreach ($entry['alerts'] as $alert) {
                $deliveries = $alert->deliveries ?? [];
                $deliveries['email']['sent_to'] = [...(array) ($deliveries['email']['sent_to'] ?? []), $user->id];
                $alert->deliveries = $deliveries;
            }
        }

        foreach ($pending as $alert) {
            if (isset($skipped[$alert->id])) {
                continue;
            }

            isset($failed[$alert->id])
                ? $this->recordFailure($alert, 'email', $now)
                : $this->record($alert, 'email', 'sent', $now);
        }
    }

    /**
     * The channels of the alert's rule that need a delivery.
     *
     * @return list<string>
     */
    private function channels(Alert $alert): array
    {
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(Alert $alert, string $channel, string $status, CarbonImmutable $now, array $extra = []): void
    {
        $deliveries = $alert->deliveries ?? [];
        $current = $deliveries[$channel] ?? [];
        $deliveries[$channel] = [
            ...$current,
            ...$extra,
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
