import { Controller } from '@hotwired/stimulus';

const MODEL_STORAGE_KEY = 'finance.chat.model';

export default class extends Controller {
    static targets = ['model', 'effort', 'fallback', 'slider', 'enhanced', 'current', 'steps', 'menu', 'selected'];
    static values = { restore: Boolean };

    connect() {
        if (this.restoreValue) this.restoreModel();
        this.update();
        this.fallbackTarget.hidden = true;
        this.enhancedTarget.hidden = false;
    }

    disconnect() {
        this.fallbackTarget.hidden = false;
        this.enhancedTarget.hidden = true;
        this.menuTarget.open = false;
    }

    close(event) {
        if (!this.menuTarget.open) return;
        this.menuTarget.open = false;
        this.menuTarget.querySelector('summary').focus();
        event.stopPropagation();
    }

    dismiss(event) {
        if (!this.menuTarget.contains(event.target)) this.menuTarget.open = false;
    }

    restoreModel() {
        try {
            const model = localStorage.getItem(MODEL_STORAGE_KEY);
            if (Array.from(this.modelTarget.options).some(option => option.value === model)) this.modelTarget.value = model;
        } catch {
            // Browser preferences are optional; the server-rendered default remains usable.
        }
    }

    selectModel() {
        this.update();
        try {
            localStorage.setItem(MODEL_STORAGE_KEY, this.modelTarget.value);
        } catch {
            // Selecting a model still works when browser storage is unavailable.
        }
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
        this.stepsTarget.replaceChildren(...options.map((option, index) => {
            const step = document.createElement('span');
            step.textContent = this.label(option);
            step.style.left = `${index / (options.length - 1) * 100}%`;
            step.style.color = option.dataset.color;
            return step;
        }));
        this.element.style.setProperty('--chat-reasoning-gradient', `linear-gradient(to right, ${options.map(option => option.dataset.color).join(', ')})`);
        this.slide();
    }

    slide() {
        const index = Number(this.sliderTarget.value);
        const option = this.supportedOptions()[index];
        this.effortTarget.value = option.value;
        const label = this.label(option);
        this.currentTarget.textContent = label;
        this.element.style.setProperty('--chat-reasoning-color', option.dataset.color);
        this.selectedTarget.textContent = this.modelTarget.selectedOptions[0].textContent;
        this.selectedTarget.setAttribute('aria-label', `${this.selectedTarget.textContent}, reasoning: ${label}`);
        this.selectedTarget.title = `Reasoning: ${label}`;
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
