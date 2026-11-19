<?php

namespace App\Policies;

/**
 * Claims are written by the extraction (docs/spec.md 11.6); every role may read them, none changes them.
 */
class ClaimPolicy extends ReadOnlyTenantResourcePolicy {}
