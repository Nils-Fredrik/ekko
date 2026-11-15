<?php

namespace App\Actions\Social;

use RuntimeException;

/**
 * Thrown inside the SSO transaction when an invited address ends up in no tenant, to roll it back.
 */
final class NoInvitationAccepted extends RuntimeException {}
