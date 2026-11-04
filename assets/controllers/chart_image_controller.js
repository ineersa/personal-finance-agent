import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['image', 'status'];

    connect() {
        if (this.imageTarget.complete) {
            if (this.imageTarget.naturalWidth > 0) {
                this.loaded();
            } else {
                this.unavailable();
            }
        }
    }

    loaded() {
        this.imageTarget.hidden = false;
        this.statusTarget.textContent = 'Chart saved privately with this conversation.';
    }

    unavailable() {
        this.imageTarget.hidden = true;
        this.statusTarget.textContent = 'Chart unavailable. The local image could not be loaded.';
    }
}
