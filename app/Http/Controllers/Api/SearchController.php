<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\SearchResultResource;
use App\Models\Room;
use App\Services\Music\SearchService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/rooms/{code}/search?q=… — recherche unifiée Spotify + YouTube pour un invité.
 */
class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, Room $room, SearchService $search): JsonResponse
    {
        $outcome = $search->search($room, $request->validated('q'));

        return response()->json([
            'results' => SearchResultResource::collection($outcome->results)->resolve($request),
            // État de chaque source (ok / error / disabled) : l'UI peut dire "YouTube indisponible"
            // au lieu de laisser croire qu'il n'y a aucun résultat.
            'sources' => $outcome->sources,
        ]);
    }
}
