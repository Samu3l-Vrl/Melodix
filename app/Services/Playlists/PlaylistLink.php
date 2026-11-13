<?php

namespace App\Services\Playlists;

use App\Enums\Provider;

/**
 * Lien de playlist reconnu : la source et l'identifiant de la playlist chez cette source.
 */
final readonly class PlaylistLink
{
    public function __construct(
        public Provider $provider,
        public string $id,
    ) {}

    /**
     * Reconnaît les formes de liens qu'un hôte peut copier depuis les applications :
     *  - https://open.spotify.com/playlist/{id}?si=…   (aussi /intl-fr/playlist/…)
     *  - spotify:playlist:{id}
     *  - un identifiant Spotify seul (22 caractères)
     *  - https://www.youtube.com/playlist?list={id}
     *  - https://www.youtube.com/watch?v=…&list={id}, https://music.youtube.com/playlist?list={id}, youtu.be/…?list=
     *
     * Null si le lien n'est pas reconnu.
     */
    public static function parse(string $input): ?self
    {
        $input = trim($input);

        if (preg_match('#^spotify:playlist:([A-Za-z0-9]{10,64})$#', $input, $m) === 1) {
            return new self(Provider::Spotify, $m[1]);
        }

        if (preg_match('#^https?://open\.spotify\.com/(?:intl-[a-z-]+/)?(?:user/[^/]+/)?playlist/([A-Za-z0-9]{10,64})#i', $input, $m) === 1) {
            return new self(Provider::Spotify, $m[1]);
        }

        if (preg_match('#^[A-Za-z0-9]{22}$#', $input) === 1) {
            return new self(Provider::Spotify, $input);
        }

        if (preg_match('#^https?://(?:www\.|m\.|music\.)?(?:youtube\.com|youtu\.be)/#i', $input) === 1) {
            parse_str((string) parse_url($input, PHP_URL_QUERY), $query);
            $list = $query['list'] ?? null;

            if (is_string($list) && preg_match('#^[A-Za-z0-9_-]{10,64}$#', $list) === 1) {
                return new self(Provider::YouTube, $list);
            }
        }

        return null;
    }
}
