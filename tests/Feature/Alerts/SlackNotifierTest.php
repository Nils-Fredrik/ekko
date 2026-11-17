<?php

use App\Alerts\SlackNotifier;
use Illuminate\Support\Facades\Http;

// T39-F10: only plain https addresses on the Slack hosts are called (docs/spec.md 11.1 and 16).

test('a Slack incoming webhook is accepted', function () {
    expect(app(SlackNotifier::class)->accepts('https://hooks.slack.com/services/T000/B000/XXXXXXXX'))->toBeTrue();
});

test('a webhook address with a port, user info, another scheme or another host is refused', function (string $url) {
    Http::fake();

    $slack = app(SlackNotifier::class);

    expect($slack->accepts($url))->toBeFalse()
        ->and($slack->send($url, 'hello'))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'port' => 'https://hooks.slack.com:8443/services/T000/B000/XXXXXXXX',
    'user' => 'https://someone@hooks.slack.com/services/T000/B000/XXXXXXXX',
    'user and password' => 'https://someone:secret@hooks.slack.com/services/T000/B000/XXXXXXXX',
    'http' => 'http://hooks.slack.com/services/T000/B000/XXXXXXXX',
    'other host' => 'https://hooks.slack.com.example.net/services/T000/B000/XXXXXXXX',
    'internal address' => 'https://169.254.169.254/latest/meta-data',
    'not a URL' => 'hooks.slack.com/services/T000',
]);
