import { Controller } from '@hotwired/stimulus';

const NEAR_BOTTOM = 96;
const MAX_INPUT_HEIGHT = 160;

export default class extends Controller {
    static targets = ['scroll', 'jump', 'input', 'newChat'];
    following = true;
    summaries = new Set();

    connect() {
        this.resize = () => {
            this.element.style.setProperty('--chat-height', `${Math.max(240, window.innerHeight - this.element.getBoundingClientRect().top - 16)}px`);
            this.resizeInput();
        };
        window.addEventListener('resize', this.resize);
        this.resize();
        if (!this.hasScrollTarget) return;
        this.observer = new MutationObserver(() => {
            this.updateNewChat();
            this.scrollTarget.querySelectorAll('details[id]').forEach(details => {
                details.open = this.summaries.has(details.id);
            });
            if (this.following) this.jumpToLatest();
            else this.jumpTarget.hidden = false;
        });
        this.observer.observe(this.scrollTarget, { childList: true, subtree: true });
        this.updateNewChat();
        this.jumpToLatest();
    }

    disconnect() {
        window.removeEventListener('resize', this.resize);
        this.observer?.disconnect();
    }

    updateNewChat() {
        if (!this.hasNewChatTarget) return;
        const state = this.scrollTarget.querySelector('[data-chat-state]')?.dataset.chatState;
        this.newChatTarget.disabled = this.newChatTarget.dataset.chatBusyStates.split(' ').includes(state);
    }

    resizeInput() {
        if (!this.hasInputTarget) return;
        this.inputTarget.style.height = 'auto';
        this.inputTarget.style.height = `${Math.min(this.inputTarget.scrollHeight, MAX_INPUT_HEIGHT)}px`;
    }

    handleKey(event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
        event.preventDefault();
        if (this.inputTarget.value.trim()) this.inputTarget.form.requestSubmit();
    }

    trackScroll() {
        const scroll = this.scrollTarget;
        this.following = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < NEAR_BOTTOM;
        this.jumpTarget.hidden = this.following;
    }

    jumpToLatest() {
        this.scrollTarget.scrollTop = this.scrollTarget.scrollHeight;
        this.following = true;
        this.jumpTarget.hidden = true;
    }

    rememberSummary(event) {
        if (!(event.target instanceof HTMLDetailsElement)) return;
        if (event.target.open) this.summaries.add(event.target.id);
        else this.summaries.delete(event.target.id);
    }
}
