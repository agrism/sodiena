/**
 * Global Outbound Action Tracking (SendBeacon + Auxclick)
 * Sends lightweight, non-blocking beacon to /api/act on outbound action clicks.
 */
export function initActionTracker() {
    function logAction(e) {
        // e.button: 0 = left click, 1 = middle click
        if (e.type === 'click' && e.button !== 0) return;
        if (e.type === 'auxclick' && e.button !== 1) return;

        const btn = e.target.closest('[data-act]');
        if (!btn) return;

        const eventId = btn.dataset.eventId;
        const actionType = btn.dataset.act;
        const targetUrl = btn.href || btn.dataset.url;

        if (!eventId || !actionType || !targetUrl) return;

        const payload = JSON.stringify({
            event_id: parseInt(eventId, 10),
            action_type: actionType,
            url: targetUrl,
            referer: window.location.pathname
        });

        const endpoint = '/api/act';

        try {
            if (navigator.sendBeacon) {
                const blob = new Blob([payload], { type: 'application/json' });
                navigator.sendBeacon(endpoint, blob);
            } else {
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload,
                    keepalive: true
                }).catch(() => {});
            }
        } catch (_) {
            // Silently swallow any transport errors so user navigation is never interrupted
        }
    }

    document.addEventListener('click', logAction, { passive: true });
    document.addEventListener('auxclick', logAction, { passive: true });
}
