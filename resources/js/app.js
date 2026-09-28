import './bootstrap';

import { registerEmojiPicker } from './emoji';
import { registerMentionAutocomplete } from './mentions';
import { registerTypingIndicator } from './typing';
import { registerEmergencyAlerting } from './emergency';
import { registerCallSession } from './call';
import { registerHuddleSession } from './huddle';
import { registerHuddleBanner } from './huddle-banner';
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

// Livewire tags every component request with `window.Echo.socketId()` so the
// server can skip echoing the event back to us. Before the websocket handshake
// finishes — and whenever Reverb is not running — that is undefined, and the
// header goes out as the string "undefined", which Pusher rejects outright.
// Our hook runs after Livewire's, so it can take the bad value back off.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ options }) => {
        if (! /^\d+\.\d+$/.test(options.headers?.['X-Socket-ID'] ?? '')) {
            delete options.headers?.['X-Socket-ID'];
        }
    });
});

document.addEventListener('alpine:init', () => {
    registerEmergencyAlerting(window.Alpine);
    registerMessageNotifications(window.Alpine);
    registerEmojiPicker(window.Alpine);
    registerMentionAutocomplete(window.Alpine);
    registerTypingIndicator(window.Alpine);
    registerCallSession(window.Alpine);
    registerHuddleSession(window.Alpine);
    registerHuddleBanner(window.Alpine);
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
