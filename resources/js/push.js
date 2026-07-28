/**
 * Browser push subscription. Registers the service worker and hands the
 * subscription to the server, which stores it against the signed-in user.
 */

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

function vapidKey() {
    return document.querySelector('meta[name="vapid-public-key"]')?.content || '';
}

export async function enablePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return { ok: false, reason: 'unsupported' };
    }

    const key = vapidKey();
    if (!key) {
        return { ok: false, reason: 'no-vapid-key' };
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        return { ok: false, reason: permission };
    }

    const registration = await navigator.serviceWorker.register('/service-worker.js');
    await navigator.serviceWorker.ready;

    const existing = await registration.pushManager.getSubscription();
    const subscription = existing ?? await registration.pushManager.subscribe({
        // Required by Chrome: every push must surface a visible notification.
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(key),
    });

    const payload = subscription.toJSON();

    await window.axios.post('/push-subscriptions', {
        endpoint: payload.endpoint,
        keys: payload.keys,
    });

    return { ok: true };
}

export function pushPermission() {
    return 'Notification' in window ? Notification.permission : 'unsupported';
}
