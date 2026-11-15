<?php

namespace App\Policies;

/**
 * Written by the collection and analysis jobs; every role may read it (docs/spec.md 5.2, "svar, kilder").
 */
class ResponsePolicy extends ReadOnlyTenantResourcePolicy {}
