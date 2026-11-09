import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

// UX Turbo marks its native Mercure element connected on every EventSource open.
export default class extends Controller {
    static targets = ['disconnected'];
    static values = { url: String, targetSelector: { type: String, default: '[data-import-state]' } };
    version = 0;

    connect() {
        this.tearingDown = false;
        this.streamTargets = new Set([...this.element.querySelectorAll(this.targetSelectorValue)].map(target => target.id));
        this.beforeStream = event => {
            const stream = event.detail.newStream;
            // Targeted chat updates live below the snapshot root; they invalidate stale catch-up too.
            const matchesTarget = this.streamTargets.has(stream.getAttribute('target')) ||
                stream.targetElements.some(target => [...this.element.querySelectorAll(this.targetSelectorValue)].some(root => root.contains(target)));
            if (!matchesTarget) return;
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
        this.observer = new MutationObserver(() => this.catchUp());
        this.sourceError = event => {
            if (event.currentTarget === this.source?.es) this.updateConnection();
        };
        this.sourceRecovery = event => {
            if (event.currentTarget === this.source?.es) this.hideDisconnection();
        };
        this.pageHide = () => {
            this.tearingDown = true;
            this.hideDisconnection();
        };
        this.pageShow = () => {
            this.tearingDown = false;
            this.updateConnection();
        };
        window.addEventListener('pagehide', this.pageHide);
        window.addEventListener('pageshow', this.pageShow);
        this.catchUp();
    }

    disconnect() {
        this.tearingDown = true;
        this.hideDisconnection();
        document.removeEventListener('turbo:before-stream-render', this.beforeStream);
        ++this.version;
        this.observer?.disconnect();
        this.unbindSource();
        this.source = null;
        this.eventSource = null;
        window.removeEventListener('pagehide', this.pageHide);
        window.removeEventListener('pageshow', this.pageShow);
        this.request?.abort();
    }

    unbindSource() {
        this.eventSource?.removeEventListener('error', this.sourceError);
        this.eventSource?.removeEventListener('open', this.sourceRecovery);
        this.eventSource?.removeEventListener('message', this.sourceRecovery);
    }

    syncSource() {
        const source = document.querySelector('#import-live-subscription turbo-mercure-stream-source');
        if (source !== this.source) {
            this.observer?.disconnect();
            this.source = source;
            if (source) this.observer?.observe(source, { attributes: true, attributeFilter: ['connected', 'src', 'private'] });
        }
        const eventSource = source?.es;
        if (eventSource === this.eventSource) return;
        this.unbindSource();
        this.eventSource = eventSource;
        this.eventSource?.addEventListener('error', this.sourceError);
        this.eventSource?.addEventListener('open', this.sourceRecovery);
        this.eventSource?.addEventListener('message', this.sourceRecovery);
        this.hideDisconnection();
    }

    updateConnection() {
        this.syncSource();
        if (!this.hasDisconnectedTarget) return;
        // UX Turbo clears es when intentionally detaching/replacing its source.
        // Only the current attached source's terminal state warrants a reload.
        this.disconnectedTarget.hidden = this.tearingDown || !this.element.isConnected || !this.source?.isConnected ||
            !this.eventSource || this.eventSource !== this.source.es || this.eventSource.readyState !== EventSource.CLOSED;
    }

    hideDisconnection() {
        if (this.hasDisconnectedTarget) this.disconnectedTarget.hidden = true;
    }

    catchUpOnChatEvent(event) {
        this.updateConnection();
        // Live progress can precede a canonical checkpoint, including at submission acknowledgement.
        if (this.source?.hasAttribute('connected') && this.element.querySelector('[data-chat-execution] [data-chat-response-active]')) return;
        return this.catchUp(event);
    }

    async catchUp(event) {
        this.updateConnection();
        // Disconnected chat focus and submission acknowledgements can still read saved state.
        if (!event && !this.source?.hasAttribute('connected')) return;
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
            if (error.name !== 'AbortError') console.warn('Live status catch-up unavailable.');
        }
    }
}
