<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'workspace' => function () use ($request) {
                $ws = $request->user()?->currentWorkspace();
                if (! $ws) {
                    return null;
                }
                $plan = $ws->subscription?->plan ?? 'free';

                return [
                    'id' => $ws->id,
                    'name' => $ws->name,
                    'plan' => $plan,
                    'credits' => $ws->creditBalance(),
                    'credits_quota' => config("spekta.plans.$plan.blueprints_per_month"),
                    'projects_count' => $ws->projects()->count(),
                ];
            },
        ]);
    }
}
