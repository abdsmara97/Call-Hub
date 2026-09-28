<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/**
 * Livewire tags every component request with `window.Echo.socketId()`. Until the
 * websocket handshake completes — and whenever Reverb is not running — that is
 * undefined, and arrives as the string "undefined". Laravel hands the header
 * straight to Pusher for `->toOthers()`, and Pusher throws on anything that is
 * not "\d+.\d+", turning an ordinary message send into a 500.
 *
 * These pin the scrubbing, and that it is actually wired into the web group.
 */
beforeEach(function () {
    Route::middleware('web')->get('/_test/socket-id', fn () => response()->json([
        'socket' => Broadcast::socket(),
    ]));
});

it('drops a socket id that pusher would reject', function () {
    $this->withHeader('X-Socket-ID', 'undefined')
        ->get('/_test/socket-id')
        ->assertOk()
        ->assertExactJson(['socket' => null]);
});

it('keeps a real socket id, so toOthers still skips the sender', function () {
    $this->withHeader('X-Socket-ID', '123456.7891011')
        ->get('/_test/socket-id')
        ->assertOk()
        ->assertExactJson(['socket' => '123456.7891011']);
});

it('leaves a request with no socket id alone', function () {
    $this->get('/_test/socket-id')
        ->assertOk()
        ->assertExactJson(['socket' => null]);
});

it('drops anything else shaped wrongly', function (string $header) {
    $this->withHeader('X-Socket-ID', $header)
        ->get('/_test/socket-id')
        ->assertOk()
        ->assertExactJson(['socket' => null]);
})->with([
    'null as a string' => 'null',
    'empty' => '',
    'no fraction' => '123456',
    'not a number' => 'abc.def',
    'trailing junk' => '123.456;drop',
]);
