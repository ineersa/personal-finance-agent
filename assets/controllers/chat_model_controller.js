import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['model', 'effort'];

    connect() {
        this.update();
    }

    update() {
        const supported = JSON.parse(this.modelTarget.selectedOptions[0].dataset.efforts);
        for (const option of this.effortTarget.options) {
            option.disabled = option.hidden = !supported.includes(option.value);
        }
        if (!supported.includes(this.effortTarget.value)) this.effortTarget.value = supported[0];
    }
}
