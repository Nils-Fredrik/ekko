<?php

namespace App\Policies;

/**
 * The prompts of an action are written with the action (ActionGenerator, ActionWorkflow); every role may read them.
 */
class ActionPromptPolicy extends ReadOnlyTenantResourcePolicy {}
