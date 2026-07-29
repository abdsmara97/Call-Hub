import './bootstrap';

import { registerEmojiPicker } from './emoji';
import { registerMentionAutocomplete } from './mentions';
import { registerEmergencyAlerting } from './emergency';
import {
    notificationPermission,
    registerMessageNotifications,
    requestNotificationPermission,
} from './notifications';
import { enablePush, pushPermission } from './push';

// Exposed for the profile screen's notification buttons.
window.OakTreeHub = {
    enablePush,
    pushPermission,
    requestNotificationPermission,
    notificationPermission,
};

document.addEventListener('alpine:init', () => {
    registerEmergencyAlerting(window.Alpine);
    registerMessageNotifications(window.Alpine);
    registerEmojiPicker(window.Alpine);
    registerMentionAutocomplete(window.Alpine);
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
