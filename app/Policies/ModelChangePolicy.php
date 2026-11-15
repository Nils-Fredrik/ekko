<?php

namespace App\Policies;

/**
 * Written by the daily aggregation; every role may read it (docs/spec.md 7.5, markers in the dashboard).
 */
class ModelChangePolicy extends ReadOnlyTenantResourcePolicy {}
