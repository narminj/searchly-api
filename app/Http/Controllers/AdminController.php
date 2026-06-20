<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\View\View;

class AdminController extends Controller
{
    /** Admin-only user listing — placeholder for a future admin panel. */
    public function users(): View
    {
        return view('admin.users', [
            'users' => User::query()->orderByDesc('role')->orderBy('name')->get(),
        ]);
    }
}
