import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

// UX Turbo marks its native Mercure element connected on every EventSource open.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.source = document.querySelector('#import-live-subscription turbo-mercure-stream-source');
        if (!this.source) return;
        this.observer = new MutationObserver(() => this.catchUp());
        this.observer.observe(this.source, { attributes: true, attributeFilter: ['connected'] });
        this.catchUp();
    }

    disconnect() {
        this.observer?.disconnect();
        this.request?.abort();
    }

    async catchUp() {
        if (!this.source.hasAttribute('connected')) return;
        this.request?.abort();
        const request = this.request = new AbortController();
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                cache: 'no-store',
                signal: request.signal,
            });
            if (!response.ok || response.status === 204) return;
            const html = await response.text();
            if (!request.signal.aborted && this.element.isConnected) renderStreamMessage(html);
        } catch (error) {
            // Native EventSource owns reconnects. No polling or page reload fallback.
            if (error.name !== 'AbortError') console.warn('Statement status catch-up unavailable.');
        }
    }
}
