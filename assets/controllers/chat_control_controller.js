import { Controller } from '@hotwired/stimulus';

const STATUS = Object.freeze({ pending: 'pending', running: 'running', completed: 'completed', failed: 'failed', cancelled: 'cancelled' });
const REFRESH_MILLISECONDS = 2000;
const TERMINAL_EVENT = 'terminal';

export default class extends Controller {
    static targets = ['button'];
    static values = { conversation: String, statusUrl: String, stopUrl: String, token: String };

    connect() {
        this.connected = true;
        this.refresh();
    }

    disconnect() {
        this.connected = false;
        clearTimeout(this.timer);
        this.request?.abort();
    }

    buttonTargetConnected(button) {
        this.render(JSON.parse(button.dataset.chatControlState || 'null'));
    }

    submit(event) {
        if (this.busy) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        const input = event.target.querySelector('[data-chat-view-target="input"]');
        if (!input?.value.trim()) return;
        this.revision = (this.revision || 0) + 1;
        this.render({ turn: '', status: STATUS.pending, stopping: false });
    }

    acknowledged(event) {
        if (!event.detail.success) this.render(null);
        this.refresh();
    }

    async stop(event) {
        if (!this.busy) return;
        event.preventDefault();
        if (!this.state?.turn || this.stopping) return;
        this.stopping = this.state.turn;
        this.render({ ...this.state, stopping: true });
        try {
            const response = await fetch(this.stopUrlValue, {
                method: 'POST',
                body: new URLSearchParams({ conversation: this.conversationValue, turn: this.state.turn, _token: this.tokenValue }),
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Stop was not acknowledged.');
            if (this.connected) this.render(await response.json());
        } catch {
            if (!this.connected) return;
            this.stopping = null;
            this.render({ ...this.state, stopping: false });
            this.notice('Could not request Stop. Try again.');
        }
    }

    async refresh() {
        if (!this.connected || !this.conversationValue || this.refreshing) return;
        clearTimeout(this.timer);
        this.refreshing = true;
        const revision = this.revision;
        this.request = new AbortController();
        try {
            const response = await fetch(this.statusUrlValue, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: this.request.signal });
            if (!response.ok) throw new Error('Status unavailable.');
            const state = await response.json();
            if (this.connected && revision === this.revision) this.render(state);
        } catch (error) {
            if (this.connected && error.name !== 'AbortError') this.notice('Unable to verify response status. Reconnect or reload to check it.');
        } finally {
            this.refreshing = false;
            if (this.connected && this.busy) this.timer = setTimeout(() => this.refresh(), REFRESH_MILLISECONDS);
        }
    }

    render(state) {
        const wasBusy = Boolean(this.busy);
        const previousTurn = this.busy ? this.state?.turn : null;
        this.state = state;
        this.busy = state && [STATUS.pending, STATUS.running].includes(state.status);
        if (!this.hasButtonTarget) return;
        const stopping = this.busy && (state.stopping || this.stopping === state.turn);
        this.buttonTarget.type = this.busy ? 'button' : 'submit';
        this.buttonTarget.disabled = Boolean(this.busy && (!state.turn || stopping));
        const label = this.busy ? (stopping ? 'Stopping…' : 'Stop') : 'Send message';
        this.buttonTarget.replaceChildren(document.createTextNode(`${label} `));
        const icon = document.createElement('span');
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = this.busy ? '■' : '↑';
        this.buttonTarget.append(icon);
        if (!this.busy) {
            this.stopping = null;
            const waiting = this.element.querySelector('[data-chat-view-target="submission"]');
            if (waiting) waiting.hidden = true;
            const turn = state?.turn || previousTurn;
            if (turn) {
                const reply = document.getElementById(`chat-reply-${this.conversationValue}-${turn}`);
                reply?.querySelectorAll('[data-chat-response-active]').forEach(element => element.removeAttribute('data-chat-response-active'));
                if (!state || [STATUS.cancelled, STATUS.failed].includes(state.status)) {
                    const progress = document.getElementById(`chat-status-${this.conversationValue}-${turn}`);
                    if (progress) {
                        progress.removeAttribute('data-controller');
                        progress.textContent = !state ? 'Response status expired. Check saved replies.' : (state.status === STATUS.cancelled ? 'Stopped' : 'Failed');
                    }
                }
            }
        }
        if (this.connected && this.busy && !this.refreshing) {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.refresh(), REFRESH_MILLISECONDS);
        }
        if (wasBusy && !this.busy) this.dispatch(TERMINAL_EVENT);
    }

    notice(message) {
        const error = this.element.querySelector('[data-chat-view-target="error"]');
        if (error) error.textContent = message;
    }
}
