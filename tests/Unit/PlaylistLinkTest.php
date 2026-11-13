<?php

// tests/Unit/PlaylistLinkTest.php — reconnaissance des liens de playlist Spotify et YouTube.

use App\Enums\Provider;
use App\Services\Playlists\PlaylistLink;

it('reconnaît les liens de playlist', function (string $input, Provider $provider, string $id) {
    $link = PlaylistLink::parse($input);

    expect($link)->not->toBeNull()
        ->and($link->provider)->toBe($provider)
        ->and($link->id)->toBe($id);
})->with([
    'Spotify, lien de partage' => ['https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M?si=abc123', Provider::Spotify, '37i9dQZF1DXcBWIGoYBM5M'],
    'Spotify, lien localisé' => ['https://open.spotify.com/intl-fr/playlist/3cEYpjA9oz9GiPac4AsH4n', Provider::Spotify, '3cEYpjA9oz9GiPac4AsH4n'],
    'Spotify, URI' => ['spotify:playlist:3cEYpjA9oz9GiPac4AsH4n', Provider::Spotify, '3cEYpjA9oz9GiPac4AsH4n'],
    'Spotify, identifiant seul' => ['3cEYpjA9oz9GiPac4AsH4n', Provider::Spotify, '3cEYpjA9oz9GiPac4AsH4n'],
    'Spotify, espaces autour' => ['  https://open.spotify.com/playlist/3cEYpjA9oz9GiPac4AsH4n  ', Provider::Spotify, '3cEYpjA9oz9GiPac4AsH4n'],
    'YouTube, page playlist' => ['https://www.youtube.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI', Provider::YouTube, 'PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI'],
    'YouTube, vidéo dans une playlist' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI&index=2', Provider::YouTube, 'PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI'],
    'YouTube Music' => ['https://music.youtube.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI', Provider::YouTube, 'PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI'],
    'YouTube, lien court' => ['https://youtu.be/dQw4w9WgXcQ?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI', Provider::YouTube, 'PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI'],
]);

it('refuse ce qui n\'est pas un lien de playlist', function (string $input) {
    expect(PlaylistLink::parse($input))->toBeNull();
})->with([
    'vide' => [''],
    'texte libre' => ['ma playlist de soirée'],
    'album Spotify' => ['https://open.spotify.com/album/3cEYpjA9oz9GiPac4AsH4n'],
    'morceau Spotify' => ['https://open.spotify.com/track/3cEYpjA9oz9GiPac4AsH4n'],
    'vidéo YouTube sans playlist' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'autre site' => ['https://example.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI'],
]);
