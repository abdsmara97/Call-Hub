import './bootstrap';

import { registerEmergencyAlerting } from './emergency';
import { enablePush, pushPermission } from './push';

// Exposed for the profile screen's "enable notifications" button.
window.OakTreeHub = { enablePush, pushPermission };

document.addEventListener('alpine:init', () => {
    registerEmergencyAlerting(window.Alpine);
});

// Notification clicks are handled by the service worker, which posts the target
// URL back here so an already-open tab navigates instead of opening a new one.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'navigate' && event.data.url) {
            window.location.assign(event.data.url);
        }
    });
}
