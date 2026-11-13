<?php

// tests/Feature/ChannelAuthTest.php — autorisation du canal privé hôte via POST /broadcasting/auth.

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    // Les tests tournent avec le diffuseur "null", qui autorise tout. On bascule sur le vrai
    // diffuseur Reverb (protocole Pusher : la signature est calculée localement, sans réseau)
    // puis on recharge les canaux pour qu'ils s'enregistrent auprès de lui.
    config(['broadcasting.default' => 'reverb']);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');

    $this->host = User::factory()->create();
    $this->room = makeRoom($this->host);
    $this->payload = [
        'socket_id' => '1234.5678',
        'channel_name' => "private-room.{$this->room->code}.host",
    ];
});

it('autorise le propriétaire à s\'abonner au canal privé de son salon', function () {
    $response = $this->actingAs($this->host)
        ->postJson('/broadcasting/auth', $this->payload)
        ->assertOk()
        ->assertJsonStructure(['auth']);

    // Signature Pusher : "<clé d'app>:<hmac>".
    expect($response->json('auth'))->toStartWith(config('broadcasting.connections.reverb.key').':');
});

it('refuse le canal privé à un autre hôte', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/broadcasting/auth', $this->payload)
        ->assertForbidden();
});

it('refuse le canal privé à un visiteur non connecté (invité)', function () {
    $this->postJson('/broadcasting/auth', $this->payload, guestHeaders(makeGuest($this->room)))
        ->assertForbidden();
});

it('refuse le canal privé d\'un salon inexistant', function () {
    $this->actingAs($this->host)
        ->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-room.ZZZZ.host'])
        ->assertForbidden();
});

it('accepte le code du canal quelle que soit la casse', function () {
    $this->actingAs($this->host)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-room.'.strtolower($this->room->code).'.host',
        ])
        ->assertOk();
});
