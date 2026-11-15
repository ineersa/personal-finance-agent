import { Controller } from '@hotwired/stimulus';

const SECOND = 1000;
const LONG_PAUSE = 10 * SECOND;

// Local presentation clock only: no inference, delivery or execution-state assumptions.
export default class extends Controller {
    static targets = ['label', 'elapsed'];

    connect() {
        this.started = Date.now();
        this.updated = this.started;
        this.observer = new MutationObserver(mutations => {
            if (mutations.some(({ target }) => !this.element.contains(target) || this.labelTarget.contains(target))) this.updated = Date.now();
        });
        this.observer.observe(this.element.parentElement, { childList: true, characterData: true, subtree: true });
        this.timer = window.setInterval(() => {
            const now = Date.now();
            const prefix = now - this.updated >= LONG_PAUSE ? 'Still working · ' : '· ';
            this.elapsedTarget.textContent = `${prefix}${Math.floor((now - this.started) / SECOND)}s`;
        }, SECOND);
    }

    disconnect() {
        window.clearInterval(this.timer);
        this.observer?.disconnect();
    }
}
