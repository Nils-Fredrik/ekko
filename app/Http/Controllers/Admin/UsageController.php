<?php

namespace App\Http\Controllers\Admin;

use App\AnthropicBudget\Account;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class UsageController extends Controller
{
    public function __invoke(Request $request, Account $account): Response
    {
        abort_unless($request->user()?->is_platform_admin, 403);

        return Inertia::render('Admin/Usage', ['anthropic' => $account->summary()]);
    }
}
