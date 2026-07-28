<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens (or lazily creates) the DM room between the signed-in user and someone
 * from the directory, then hands off to the normal room view.
 */
class StartDirectMessageController extends Controller
{
    public function __invoke(Request $request, User $user, RoomProvisioner $rooms): RedirectResponse
    {
        $me = $request->user();

        abort_if($me->is($user), 400, 'You cannot message yourself.');
        abort_unless($user->isActive(), 404);

        $room = $rooms->findOrCreateDm($me, $user);

        return redirect()->route('rooms.show', $room);
    }
}
