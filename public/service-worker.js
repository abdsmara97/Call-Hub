/**
 * Push handler for Saai.
 *
 * Deliberately minimal: no offline caching, no asset interception. Its only job
 * is to surface an emergency notification when the tab is closed or in the
 * background, and to focus the right conversation when it is clicked.
 */

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload = {};
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Emergency', body: event.data.text() };
    }

    const data = payload.data || {};

    const options = {
        body: payload.body,
        icon: payload.icon || '/images/emergency-icon.png',
        badge: payload.badge || '/images/emergency-badge.png',
        tag: payload.tag,
        // Stays on screen until dismissed, and re-alerts even when a
        // notification with the same tag is already showing.
        requireInteraction: data.requireInteraction !== false,
        renotify: Boolean(data.renotify),
        vibrate: [200, 100, 200, 100, 200],
        data,
    };

    event.waitUntil(self.registration.showNotification(payload.title || 'Emergency', options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if ('focus' in client) {
                    client.postMessage({ type: 'navigate', url: target });

                    return client.focus();
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
