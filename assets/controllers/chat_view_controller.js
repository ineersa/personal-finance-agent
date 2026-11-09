import { Controller } from '@hotwired/stimulus';

const NEAR_BOTTOM = 96;
const MAX_INPUT_HEIGHT = 160;

export default class extends Controller {
    static targets = ['scroll', 'jump', 'input', 'error', 'submission'];
    inputRevision = 0;
    following = true;
    summaries = new Set();

    connect() {
        this.resize = () => {
            this.element.style.setProperty('--chat-height', `${Math.max(240, window.innerHeight - this.element.getBoundingClientRect().top - 16)}px`);
            this.resizeInput();
        };
        window.addEventListener('resize', this.resize);
        this.resize();
        this.submittedTurnIds = new Set();
        if (this.hasSubmissionTarget && !this.submissionTarget.hidden) this.waitForProgress();
        if (!this.hasScrollTarget) return;
        this.observer = new MutationObserver(() => {
            if (this.waitingForProgress && this.hasSubmittedTurn()) this.clearSubmission();
            this.scrollTarget.querySelectorAll('details[id]').forEach(details => {
                details.open = this.summaries.has(details.id);
            });
            if (this.following) this.jumpToLatest();
            else if (this.hasJumpTarget) this.jumpTarget.hidden = false;
        });
        this.observer.observe(this.scrollTarget, { childList: true, subtree: true });
        this.jumpToLatest();
    }

    disconnect() {
        window.removeEventListener('resize', this.resize);
        this.observer?.disconnect();
        this.clearSubmission();
    }

    resizeInput(event) {
        if (event?.type === 'input') ++this.inputRevision;
        if (!this.hasInputTarget) return;
        this.inputTarget.style.height = 'auto';
        this.inputTarget.style.height = `${Math.min(this.inputTarget.scrollHeight, MAX_INPUT_HEIGHT)}px`;
    }

    submissionStarted(event) {
        if (event.type === 'submit' && event.target.dataset.turbo === 'true') return;
        this.submittedRevision = this.inputRevision;
        this.submittedTurnIds = new Set([...this.element.querySelectorAll('.chat-turn[id]')].map(turn => turn.id));
        this.waitingForProgress = false;
        if (this.hasErrorTarget) this.errorTarget.textContent = '';
        if (this.hasSubmissionTarget) {
            this.submissionTarget.hidden = false;
            this.submissionTarget.textContent = 'Sending message…';
        }
    }

    submissionFinished(event) {
        if (event.target.dataset.turbo !== 'true') return;
        if (!event.detail.success) {
            this.clearSubmission();
            return;
        }
        this.waitForProgress();
        // A delayed HTTP acknowledgement cannot erase a later edited draft.
        if (this.hasInputTarget && this.submittedRevision === this.inputRevision) {
            this.inputTarget.value = '';
            this.resizeInput();
        }
        this.dispatch('acknowledged', { target: event.target });
    }

    waitForProgress() {
        if (!this.hasSubmissionTarget) return;
        if (this.hasSubmittedTurn()) {
            this.clearSubmission();
            return;
        }
        this.submissionTarget.hidden = false;
        this.waitingForProgress = true;
        this.submissionTarget.textContent = 'Message sent. Waiting for a response…';
    }

    hasSubmittedTurn() {
        // A native input checkpoint supplies a real user turn, even before any
        // answer tokens. Empty snapshots and updates to older replies do not.
        return [...this.element.querySelectorAll('.chat-turn[id]')].some(turn =>
            !this.submittedTurnIds.has(turn.id) && turn.querySelector('.chat-question p')?.textContent.trim()
        );
    }

    clearSubmission() {
        this.waitingForProgress = false;
        if (this.hasSubmissionTarget) this.submissionTarget.hidden = true;
    }

    handleKey(event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
        event.preventDefault();
        const send = this.inputTarget.form.querySelector('[data-chat-send]');
        if (this.inputTarget.value.trim() && send && !send.disabled) this.inputTarget.form.requestSubmit(send);
    }

    trackScroll() {
        const scroll = this.scrollTarget;
        this.following = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < NEAR_BOTTOM;
        if (this.hasJumpTarget) this.jumpTarget.hidden = this.following;
    }

    jumpToLatest() {
        this.scrollTarget.scrollTop = this.scrollTarget.scrollHeight;
        this.following = true;
        if (this.hasJumpTarget) this.jumpTarget.hidden = true;
    }

    rememberSummary(event) {
        if (!(event.target instanceof HTMLDetailsElement) || !this.element.contains(event.target)) return;
        if (event.target.open) this.summaries.add(event.target.id);
        else this.summaries.delete(event.target.id);
    }
}
