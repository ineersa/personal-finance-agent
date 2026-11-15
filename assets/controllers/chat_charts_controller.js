import { Controller } from '@hotwired/stimulus';

const HTML_SUFFIX = '.html';

export default class extends Controller {
    static targets = ['link', 'panel', 'title', 'select', 'views'];
    charts = new Map();

    connect() {
        this.syncLinks();
    }

    linkTargetConnected() {
        // Target callbacks can run before panel targets connect on initial navigation.
        queueMicrotask(() => { if (this.element.isConnected) this.syncLinks(); });
    }

    disconnect() {
        this.charts.clear();
    }

    syncLinks() {
        if (!this.hasPanelTarget) return;
        let newest;
        this.linkTargets.forEach(link => {
            const url = link.dataset.chartUrl;
            if (this.charts.has(url)) return;
            const title = link.dataset.chartTitle;
            this.charts.set(url, { title });
            this.selectTarget.add(new Option(title, url));
            newest = url;
        });
        this.selectTarget.hidden = this.charts.size < 2;
        // Only genuinely new URLs auto-open, not replacements of already-seen turn markup.
        if (newest) this.show(newest);
        this.updateLinks();
    }

    open(event) {
        this.opener = event.currentTarget;
        this.show(event.currentTarget.dataset.chartUrl);
    }

    select() {
        this.show(this.selectTarget.value);
    }

    close() {
        this.panelTarget.hidden = true;
        this.element.classList.remove('has-chart-panel');
        this.updateLinks();
        if (this.opener?.isConnected) this.opener.focus({ preventScroll: true });
        else this.element.querySelector('[data-chat-view-target="input"]')?.focus({ preventScroll: true });
    }

    show(url) {
        const chart = this.charts.get(url);
        if (!chart) return;
        this.panelTarget.hidden = false;
        this.element.classList.add('has-chart-panel');
        this.selected = url;
        this.selectTarget.value = url;
        this.titleTarget.textContent = chart.title;
        if (!chart.view) this.createView(url, chart);
        this.charts.forEach(entry => { if (entry.view) entry.view.hidden = entry !== chart; });
        this.updateLinks();
    }

    createView(url, chart) {
        const view = document.createElement('figure');
        view.className = 'chat-chart';
        view.setAttribute('aria-label', chart.title);
        const status = document.createElement('figcaption');
        status.className = 'chat-chart-loading';
        status.setAttribute('role', 'status');
        status.textContent = 'Loading chart…';
        const documentElement = document.createElement(url.endsWith(HTML_SUFFIX) ? 'iframe' : 'img');
        documentElement.referrerPolicy = 'no-referrer';
        if (url.endsWith(HTML_SUFFIX)) {
            documentElement.title = chart.title;
            documentElement.setAttribute('sandbox', 'allow-scripts');
        } else documentElement.alt = chart.title;
        documentElement.addEventListener('load', () => { status.hidden = true; }, { once: true });
        documentElement.addEventListener('error', () => { status.textContent = 'Chart unavailable. Reopen the conversation to try again.'; }, { once: true });
        documentElement.src = url;
        view.append(status, documentElement);
        this.viewsTarget.append(view);
        chart.view = view;
    }

    updateLinks() {
        this.linkTargets.forEach(link => link.setAttribute('aria-expanded', String(!this.panelTarget.hidden && link.dataset.chartUrl === this.selected)));
    }
}
