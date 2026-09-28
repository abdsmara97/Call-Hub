/**
 * A JSON POST that carries the CSRF token and the session cookie.
 *
 * Deliberately a copy of the private helper in call.js rather than a refactor of
 * it. The 1:1 call stack is the tested, shipped path and huddles were built
 * without touching a line of it; extracting a shared helper would have meant
 * editing call.js for the benefit of a feature it does not know about. This is
 * the honest cost of that rule, and it is about twenty lines.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export async function postJson(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, payload };
}

/** Announced politely: a huddle is an offer, never an interruption. */
export function announce(text) {
    const region = document.getElementById('a11y-announcer-polite');

    if (! region) return;

    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = text;
    }, 60);
}
