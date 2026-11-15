<?php

namespace App\Scheduling;

use Google\Auth\ApplicationDefaultCredentials;
use RuntimeException;

class CloudRunAccessToken
{
    public function get(): string
    {
        $token = ApplicationDefaultCredentials::getCredentials(['https://www.googleapis.com/auth/cloud-platform'])->fetchAuthToken()['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('cloud_run_auth_failed');
        }

        return $token;
    }
}
