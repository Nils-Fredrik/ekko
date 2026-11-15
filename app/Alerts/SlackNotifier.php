<?php

namespace App\Alerts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts a message to the tenant's Slack incoming webhook (docs/spec.md 11.1). The webhook is a secret: it is
 * never logged, and only https addresses on the hosts in ekko.alerts.slack_hosts are called, so a stored
 * address cannot make the server call an internal address (docs/spec.md 16).
 */
final class SlackNotifier
{
    public function accepts(string $webhookUrl): bool
    {
        $parts = parse_url($webhookUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), (array) config('ekko.alerts.slack_hosts'), true)
            && ! isset($parts['port'], $parts['user'], $parts['pass']);
    }

    /**
     * @return bool whether Slack accepted the message
     */
    public function send(string $webhookUrl, string $text): bool
    {
        if (! $this->accepts($webhookUrl)) {
            return false;
        }

        try {
            return Http::timeout(10)->withoutRedirecting()->asJson()->post($webhookUrl, ['text' => $text])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Slack mrkdwn needs &, < and > escaped, so a name cannot make a link or a mention.
     */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
