<?php

// tests/Unit/YouTubeDurationTest.php — conversion des durées ISO 8601 de YouTube en millisecondes.

use App\Services\Music\YouTubeProvider;

it('convertit une durée ISO 8601 en millisecondes', function (string $iso, int $expectedMs) {
    expect(YouTubeProvider::parseIsoDuration($iso))->toBe($expectedMs);
})->with([
    'minutes et secondes' => ['PT4M13S', 253_000],
    'secondes seules' => ['PT45S', 45_000],
    'heures' => ['PT1H2M3S', 3_723_000],
    'minutes rondes' => ['PT3M', 180_000],
    'direct (durée nulle)' => ['P0D', 0],
    'chaîne vide' => ['', 0],
    'valeur invalide' => ['n-importe-quoi', 0],
]);
