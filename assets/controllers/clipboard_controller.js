import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { copiedLabel: String };

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.value);

        const label = this.buttonTarget.textContent;
        this.buttonTarget.textContent = this.copiedLabelValue;
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => {
            this.buttonTarget.textContent = label;
        }, 2000);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }
}
