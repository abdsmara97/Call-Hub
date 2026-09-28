/**
 * Shared, reference-counted access to `presence.room.{id}`.
 *
 * Two unrelated features now live on this channel — the typing indicator and
 * call signalling — and they come and go independently. Without a reference
 * count the second one to leave takes the channel with it: navigating away from
 * a conversation destroys the typing indicator, which would otherwise tear down
 * the socket an active call is negotiating over.
 *
 * The subtle part is that `Echo.leave(name)` is not the inverse of
 * `Echo.join(name)`. It unsubscribes the public, private, private-encrypted AND
 * presence variants of the name in one go, so it cannot be aimed at a single
 * subscriber. `Echo.leaveChannel('presence-' + name)` is the precise version,
 * and that is what we call — once, when the last holder lets go.
 *
 * Callers own their own listeners: whatever you bind with `listenForWhisper`,
 * unbind with `stopListeningForWhisper` before you release. The channel object
 * outlives any one holder.
 */

/** roomId => number of live holders */
const holders = new Map();

/** See room-channel.js — the tenant segment fences customers apart. */
function tenantId() {
    return document.querySelector('meta[name="tenant-id"]')?.content ?? '';
}

function channelName(roomId) {
    return `tenant.${tenantId()}.presence.room.${roomId}`;
}

/**
 * Join, or return the channel already joined. Echo keys its channels by name,
 * so repeated joins hand back the same object rather than opening a second
 * subscription — the count is only about deciding when to leave.
 */
export function joinRoomPresence(roomId) {
    if (! window.Echo) return null;

    holders.set(roomId, (holders.get(roomId) ?? 0) + 1);

    return window.Echo.join(channelName(roomId));
}

/** Release one hold. The channel goes away only when the last holder does. */
export function releaseRoomPresence(roomId) {
    if (! window.Echo) return;

    const remaining = (holders.get(roomId) ?? 0) - 1;

    if (remaining > 0) {
        holders.set(roomId, remaining);

        return;
    }

    holders.delete(roomId);

    // Deliberately leaveChannel, not leave — see the note above.
    window.Echo.leaveChannel(`presence-${channelName(roomId)}`);
}

/** Test seam: how many holders a room currently has. */
export function presenceHolders(roomId) {
    return holders.get(roomId) ?? 0;
}
