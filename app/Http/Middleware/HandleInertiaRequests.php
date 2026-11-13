<?php

namespace App\Http\Middleware;

use App\Http\Resources\HostResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Middleware Inertia : définit la vue racine et les props partagées par toutes les pages.
 */
class HandleInertiaRequests extends Middleware
{
    /** Vue Blade qui contient le point de montage React (resources/views/app.blade.php). */
    protected $rootView = 'app';

    /**
     * Props disponibles sur toutes les pages (type TS `SharedProps`).
     * Les closures ne sont évaluées que si la page est réellement rendue.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
            'auth' => [
                'user' => fn () => $request->user() !== null
                    ? (new HostResource($request->user()))->resolve($request)
                    : null,
            ],
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
