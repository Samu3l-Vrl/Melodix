<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

/**
 * Classe de base de tous les tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Filet de sécurité : un test qui oublierait Http::fake() échoue au lieu d'appeler
        // réellement Spotify ou YouTube (et de consommer du quota).
        Http::preventStrayRequests();

        // Les pages Inertia sont rendues sans chercher public/build/manifest.json :
        // les tests PHP ne dépendent pas d'un `npm run build` préalable.
        $this->withoutVite();
    }
}
