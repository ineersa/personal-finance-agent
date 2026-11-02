import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

// UX Turbo marks its native Mercure element connected on every EventSource open.
export default class extends Controller {
    static values = { url: String };
    version = 0;

    connect() {
        this.target = this.element.querySelector('[data-import-state]').id;
        this.beforeStream = event => {
            const stream = event.detail.newStream;
            if (stream.getAttribute('target') !== this.target) return;
            if (stream.hasAttribute('data-import-snapshot')) {
                // Turbo renders asynchronously; recheck at render time, not just after fetch.
                const render = event.detail.render;
                event.detail.render = element => {
                    if (stream.dataset.importSnapshot === String(this.version) && this.element.isConnected) return render(element);
                };
            } else {
                ++this.version;
                this.request?.abort();
            }
        };
        document.addEventListener('turbo:before-stream-render', this.beforeStream);
        this.source = document.querySelector('#import-live-subscription turbo-mercure-stream-source');
        if (!this.source) return;
        this.observer = new MutationObserver(() => this.catchUp());
        this.observer.observe(this.source, { attributes: true, attributeFilter: ['connected'] });
        this.catchUp();
    }

    disconnect() {
        document.removeEventListener('turbo:before-stream-render', this.beforeStream);
        ++this.version;
        this.observer?.disconnect();
        this.request?.abort();
    }

    async catchUp() {
        if (!this.source.hasAttribute('connected')) return;
        this.request?.abort();
        const version = ++this.version;
        const request = this.request = new AbortController();
        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                cache: 'no-store',
                signal: request.signal,
            });
            if (!response.ok || response.status === 204) return;
            const html = await response.text();
            if (request.signal.aborted || !this.element.isConnected) return;
            const template = document.createElement('template');
            template.innerHTML = html;
            template.content.querySelectorAll('turbo-stream').forEach(stream => stream.dataset.importSnapshot = String(version));
            renderStreamMessage(template.innerHTML);
        } catch (error) {
            // Native EventSource owns reconnects. No polling or page reload fallback.
            if (error.name !== 'AbortError') console.warn('Statement status catch-up unavailable.');
        }
    }
}
