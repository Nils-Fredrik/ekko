<?php

namespace App\Policies;

/**
 * Insight texts are written by InsightService (docs/spec.md 14.2, ADR 0038); every role may read them, none changes them.
 */
class InsightPolicy extends ReadOnlyTenantResourcePolicy {}
