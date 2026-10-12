import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String, delay: { type: Number, default: 3000 } };

    connect() {
        this.timeout = setTimeout(() => {
            const frame = this.element.closest('turbo-frame');
            if (frame.getAttribute('src') === this.urlValue) {
                frame.reload();
            } else {
                frame.src = this.urlValue;
            }
        }, this.delayValue);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
