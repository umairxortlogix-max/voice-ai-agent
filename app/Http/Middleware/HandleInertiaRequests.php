<?php

namespace App\Http\Middleware;

use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(\Illuminate\Http\Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(\Illuminate\Http\Request $request): array
    {
        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
        ]);
    }
}
