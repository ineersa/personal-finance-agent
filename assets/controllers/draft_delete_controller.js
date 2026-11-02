import { Controller } from '@hotwired/stimulus';

// EasyAdmin binds confirmation handlers once, before streamed draft rows arrive.
export default class extends Controller {
    static targets = ['confirmation'];

    confirm(event) {
        this.confirmationTarget.setAttribute('formaction', event.params.url);
        this.confirmationTarget.click();
    }
}
