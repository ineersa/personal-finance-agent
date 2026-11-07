import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['model', 'effort', 'fallback', 'slider', 'enhanced', 'current', 'steps'];

    connect() {
        this.update();
        this.fallbackTarget.hidden = true;
        this.enhancedTarget.hidden = false;
    }

    disconnect() {
        this.fallbackTarget.hidden = false;
        this.enhancedTarget.hidden = true;
    }

    update() {
        const supported = JSON.parse(this.modelTarget.selectedOptions[0].dataset.efforts);
        for (const option of this.effortTarget.options) {
            option.disabled = option.hidden = !supported.includes(option.value);
        }
        if (!supported.includes(this.effortTarget.value)) this.effortTarget.value = supported[0];
        const options = this.supportedOptions();
        this.sliderTarget.max = options.length - 1;
        this.sliderTarget.value = options.findIndex(option => option.value === this.effortTarget.value);
        this.stepsTarget.replaceChildren(...options.map(option => {
            const step = document.createElement('span');
            step.textContent = this.label(option);
            return step;
        }));
        this.slide();
    }

    slide() {
        const index = Number(this.sliderTarget.value);
        const option = this.supportedOptions()[index];
        this.effortTarget.value = option.value;
        const label = this.label(option);
        this.currentTarget.textContent = label;
        this.sliderTarget.setAttribute('aria-valuetext', label);
        Array.from(this.stepsTarget.children).forEach((step, position) => step.classList.toggle('is-current', position === index));
    }

    supportedOptions() {
        return Array.from(this.effortTarget.options).filter(option => !option.disabled);
    }

    label(option) {
        return option.textContent.replace(/([a-z])([A-Z])/g, '$1 $2');
    }
}
