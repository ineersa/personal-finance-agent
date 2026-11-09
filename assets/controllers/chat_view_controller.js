import { Controller } from '@hotwired/stimulus';

const NEAR_BOTTOM = 96;
const MAX_INPUT_HEIGHT = 160;
const ACKNOWLEDGEMENT_HOLD_MS = 10000;

export default class extends Controller {
    static targets = ['scroll', 'jump', 'input', 'error', 'submission'];
    inputRevision = 0;
    progressRevision = 0;
    following = true;
    summaries = new Set();

    connect() {
        this.resize = () => {
            this.element.style.setProperty('--chat-height', `${Math.max(240, window.innerHeight - this.element.getBoundingClientRect().top - 16)}px`);
            this.resizeInput();
        };
        window.addEventListener('resize', this.resize);
        this.resize();
        if (this.hasSubmissionTarget && !this.submissionTarget.hidden) this.waitForProgress();
        if (!this.hasScrollTarget) return;
        this.observer = new MutationObserver(records => {
            if (records.some(record => record.target.closest('[data-chat-execution]'))) {
                ++this.progressRevision;
                if (this.waitingForProgress) this.clearSubmission();
            }
            if (this.element.querySelector('[data-chat-response-active]')) this.clearSubmission();
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
        this.submittedProgressRevision = this.progressRevision;
        this.waitingForProgress = false;
        if (this.hasErrorTarget) this.errorTarget.textContent = '';
        clearTimeout(this.acknowledgementTimer);
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
        if (this.element.querySelector('[data-chat-response-active]') || this.progressRevision !== this.submittedProgressRevision && this.submittedProgressRevision !== undefined) {
            this.clearSubmission();
            return;
        }
        this.submissionTarget.hidden = false;
        this.waitingForProgress = true;
        this.submissionTarget.textContent = 'Message sent. Waiting for a response…';
        clearTimeout(this.acknowledgementTimer);
        this.acknowledgementTimer = setTimeout(() => this.clearSubmission(), ACKNOWLEDGEMENT_HOLD_MS);
    }

    clearSubmission() {
        clearTimeout(this.acknowledgementTimer);
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
