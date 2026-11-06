import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];

    connect() {
        this.focusInput = () => this.inputTarget.focus();
        this.element.addEventListener('shown.bs.modal', this.focusInput);
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.modal', this.focusInput);
    }
}
