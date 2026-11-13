<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page d'accueil : connexion Spotify, création de salon, saisie d'un code pour rejoindre.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Salons encore ouverts de l'hôte connecté : permet de reprendre une soirée après un rechargement.
        $rooms = $request->user()?->rooms()
            ->where('status', RoomStatus::Open)
            ->latest()
            ->limit(5)
            ->get(['code', 'created_at'])
            ->map(fn ($room) => [
                'code' => $room->code,
                'created_at' => $room->created_at?->toIso8601String(),
            ])
            ->all() ?? [];

        return Inertia::render('Home', [
            'rooms' => $rooms,
        ]);
    }
}
