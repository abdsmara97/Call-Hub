/**
 * Shared, reference-counted access to the private `room.{id}` channel.
 *
 * The private twin of presence.js, and it exists for the same reason: the huddle
 * banner subscribes to `room.{id}` to hear `huddle.updated`, and so does
 * anything else that ever needs the room's private channel. `Echo.leave(name)`
 * unsubscribes the public, private and presence variants of a name in one go,
 * so the precise call is `Echo.leaveChannel('private-' + name)` and it must
 * happen once, when the last holder lets go.
 *
 * One caveat worth knowing: Livewire's own `#[On]` listeners hold this channel
 * too, and that hold is invisible to this counter. Releasing here therefore
 * cannot be assumed to close the socket subscription — which is fine, because
 * this module never assumes it does. Callers own their own listeners: unbind
 * what you bound before you release.
 */

/** roomId => number of live holders */
const holders = new Map();

function channelName(roomId) {
    return `room.${roomId}`;
}

export function joinRoomChannel(roomId) {
    if (! window.Echo) return null;

    holders.set(roomId, (holders.get(roomId) ?? 0) + 1);

    return window.Echo.private(channelName(roomId));
}

export function releaseRoomChannel(roomId) {
    if (! window.Echo) return;

    const remaining = (holders.get(roomId) ?? 0) - 1;

    if (remaining > 0) {
        holders.set(roomId, remaining);

        return;
    }

    holders.delete(roomId);

    // Deliberately leaveChannel, not leave — see the note above.
    window.Echo.leaveChannel(`private-${channelName(roomId)}`);
}

/** Test seam: how many holders a room currently has. */
export function roomChannelHolders(roomId) {
    return holders.get(roomId) ?? 0;
}
