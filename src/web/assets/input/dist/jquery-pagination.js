class ElementRelationsPaginator {
    /**
     * @param {Object} options
     * @param {string} options.pagerContainerSelector  CSS selector for the pager
     * @param {string} options.endpoint                URL to fetch pages from
     * @param {number} options.pageSize                items per page
     * @param {function(Array):void} options.render    callback to render an array of items
     * @param {number} [options.initialPage=0]         zero‑indexed start page
     */
    constructor({ pagerContainerSelector, endpoint, pageSize, render, initialPage = 0 }) {
        this.pagerContainer = document.querySelector(pagerContainerSelector);
        this.endpoint = endpoint;
        this.pageSize = pageSize;
        this.render = render;
        this.currentPage = initialPage;
        this.totalCount = 0;
        this.totalPages = 0;

        // kick it off
        this.gotoPage(this.currentPage);
    }

    async gotoPage(page) {
        // clamp
        if (page < 0) page = 0;
        if (this.totalPages && page >= this.totalPages) page = this.totalPages - 1;
        this.currentPage = page;

        // fetch the data
        const url = new URL(this.endpoint, window.location.origin);
        url.searchParams.set('page', this.currentPage);
        url.searchParams.set('limit', this.pageSize);

        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) {
            console.error('Pagination fetch failed:', res.statusText);
            return;
        }
        const json = await res.json();
        this.totalCount = json.totalCount;
        this.totalPages = Math.ceil(this.totalCount / this.pageSize);

        // render items and pager
        this.render(json.html);
        this._renderPager();
    }

    _renderPager() {
        // clear old
        this.pagerContainer.innerHTML = '';

        const makeBtn = (label, page, disabled = false) => {
            const btn = document.createElement('button');
            btn.textContent = label;
            btn.disabled = disabled;
            btn.addEventListener('click', () => this.gotoPage(page));
            return btn;
        };

        // « first
        this.pagerContainer.appendChild(
            makeBtn('«', 0, this.currentPage === 0)
        );
        // ‹ previous
        this.pagerContainer.appendChild(
            makeBtn('‹', this.currentPage - 1, this.currentPage === 0)
        );

        // page numbers (we’ll show up to 7 centered on current)
        const range = 7;
        let start = Math.max(0, this.currentPage - Math.floor(range/2));
        let end = start + range;
        if (end > this.totalPages) {
            end = this.totalPages;
            start = Math.max(0, end - range);
        }
        for (let p = start; p < end; p++) {
            const btn = makeBtn(p+1, p, false);
            if (p === this.currentPage) {
                btn.classList.add('active');
            }
            this.pagerContainer.appendChild(btn);
        }

        // › next
        this.pagerContainer.appendChild(
            makeBtn('›', this.currentPage + 1, this.currentPage >= this.totalPages - 1)
        );
        // » last
        this.pagerContainer.appendChild(
            makeBtn('»', this.totalPages - 1, this.currentPage >= this.totalPages - 1)
        );
    }
}