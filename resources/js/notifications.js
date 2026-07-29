/**
 * Desktop notifications for ordinary messages.
 *
 * The counterpart to emergency.js, and deliberately much quieter: no sound, no
 * title flash, no forced interaction. An emergency demands attention; a message
 * just needs to be noticed eventually.
 *
 * The server has already decided the user is allowed to see this message and is
 * not on Do Not Disturb. This module decides only whether a pop-up is the right
 * way to tell them right now.
 */

const ANNOUNCER_ID = 'a11y-announcer-polite';

/** Notifications are pointless when the user is already looking at the app. */
function tabIsHidden() {
    return typeof document.visibilityState === 'string'
        ? document.visibilityState === 'hidden'
        : Boolean(document.hidden);
}

function permissionGranted() {
    return typeof window.Notification !== 'undefined' && window.Notification.permission === 'granted';
}

/**
 * Polite live region, so a screen-reader user hears about the message even
 * though no pop-up was raised. Cleared first so identical repeat text is still
 * announced.
 */
function announce(text) {
    const region = document.getElementById(ANNOUNCER_ID);
    if (!region) return;

    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = text;
    }, 60);
}

function title(detail) {
    // In a DM the room name is the other person, so repeating the sender reads
    // as "Ivan Petrov — Ivan Petrov".
    return detail.isDm ? detail.sender : `${detail.sender} in ${detail.room}`;
}

export function registerMessageNotifications(Alpine) {
    Alpine.data('messageNotifications', () => ({
        notify(detail = {}) {
            const heading = title(detail);

            announce(`New message. ${heading}. ${detail.body || ''}`);

            // The sidebar already blinks the room; a pop-up on top of a visible
            // window is just noise.
            if (!tabIsHidden() || !permissionGranted()) {
                return;
            }

            let notification;

            try {
                notification = new window.Notification(heading, {
                    body: detail.body || '',
                    // One live notification per room: a busy room replaces its
                    // own pop-up rather than stacking ten of them.
                    tag: `oak-tree-room-${detail.roomId}`,
                    renotify: true,
                    // Emergencies stay on screen until acknowledged. A message
                    // must not — see the service worker, which defaults this on.
                    requireInteraction: false,
                    silent: false,
                });
            } catch {
                // Some browsers refuse the constructor outside a service worker.
                // Nothing to recover: the in-app blink still covers it.
                return;
            }

            notification.onclick = () => {
                window.focus();
                notification.close();

                if (detail.url) {
                    window.location.assign(detail.url);
                }
            };
        },
    }));
}

/**
 * Asks for permission. Must be called from a real user gesture — Chrome ignores
 * prompts that are not, which is why this is never called on page load.
 */
export async function requestNotificationPermission() {
    if (typeof window.Notification === 'undefined') {
        return { ok: false, reason: 'unsupported' };
    }

    if (window.Notification.permission === 'granted') {
        return { ok: true };
    }

    if (window.Notification.permission === 'denied') {
        // Only the browser's own site settings can undo this.
        return { ok: false, reason: 'denied' };
    }

    const permission = await window.Notification.requestPermission();

    return permission === 'granted' ? { ok: true } : { ok: false, reason: permission };
}

export function notificationPermission() {
    return typeof window.Notification !== 'undefined' ? window.Notification.permission : 'unsupported';
}
