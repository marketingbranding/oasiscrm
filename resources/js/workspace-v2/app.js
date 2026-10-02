const text = (value) => value === null || value === undefined || value === '' ? 'Belum diisi' : String(value);

const escapeHtml = (value) => text(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));

const detailMarkup = (data, detailUrl) => {
    const overview = data.overview || {};
    const process = data.process || [];
    const payment = data.payment || [];
    const activity = data.activity || [];
    const fields = [
        ['Konsumen', overview.customer_name], ['ID Transaksi', overview.id_transaksi], ['Proyek', overview.project],
        ['Kavling', overview.kavling], ['Sales', overview.sales], ['Cara Pembayaran', overview.payment_method],
        ['Bank', overview.bank_current], ['Proses Saat Ini', overview.current_stage], ['Status', overview.transaction_status],
    ];
    const formBase = `${detailUrl.replace(/\/$/, '')}/input/`;
    const actions = ['psjb', 'bi-checking', 'pemberkasan', 'proses-bank', 'ppjb-dev', 'akad', 'bast', 'garansi', 'kendala'];
    return `<section class="workspace-v2-drawer-section"><h3>Ringkasan</h3><dl class="workspace-v2-detail-grid">${fields.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}</dl><div class="workspace-v2-drawer-actions">${actions.map((action) => `<a class="workspace-v2-button workspace-v2-button--quiet" href="${escapeHtml(formBase + action)}">Input ${escapeHtml(action.replaceAll('-', ' '))}</a>`).join('')}</div></section>
        <section class="workspace-v2-drawer-section"><h3>Proses</h3>${process.length ? `<div>${process.map((item) => `<div class="workspace-v2-timeline-item"><strong>${escapeHtml(item.stage)}</strong><small>${escapeHtml(item.event_date || item.occurred_at || '')} · ${escapeHtml(item.status || item.decision || 'Dicatat')}${item.summary ? ` · ${escapeHtml(item.summary)}` : ''}</small>${item.notes ? `<small>${escapeHtml(item.notes)}</small>` : ''}</div>`).join('')}</div>` : '<p class="workspace-v2-muted">Belum ada riwayat proses.</p>'}</section>
        <section class="workspace-v2-drawer-section"><h3>Pembayaran</h3>${payment.length ? payment.map((item) => `<div class="workspace-v2-activity-row"><span><strong>Pengajuan ${escapeHtml(item.attempt_no)}</strong><small>${escapeHtml(item.bank_name)} · ${escapeHtml(item.status || item.response_type)}${item.sp3k_at ? ` · SP3K terbit ${escapeHtml(item.sp3k_at)}` : ''}</small></span></div>`).join('') : '<p class="workspace-v2-muted">Belum ada pengajuan bank.</p>'}</section>
        <section class="workspace-v2-drawer-section"><h3>Riwayat</h3>${activity.length ? activity.map((item) => `<div class="workspace-v2-activity-row"><span><strong>${escapeHtml(item.description)}</strong><small>${escapeHtml(item.created_at)}${item.source ? ` · ${escapeHtml(item.source)}` : ''}</small></span></div>`).join('') : '<p class="workspace-v2-muted">Belum ada aktivitas.</p>'}</section>`;
};

const openDetail = async (url, drawer) => {
    const title = drawer.querySelector('[data-detail-title]');
    const body = drawer.querySelector('[data-detail-body]');
    drawer.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    body.innerHTML = '<div class="workspace-v2-drawer-loading">Memuat detail transaksi...</div>';
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error('detail');
        const payload = await response.json();
        title.textContent = payload.data?.overview?.customer_name || 'Detail transaksi';
        body.innerHTML = detailMarkup(payload.data || {}, url);
    } catch (error) {
        body.innerHTML = '<div class="workspace-v2-empty"><div class="workspace-v2-empty-mark">!</div><h2>Detail tidak tersedia</h2><p>Periksa koneksi atau scope akses lalu coba lagi.</p></div>';
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const shell = document.querySelector('[data-workspace-shell]');
    const sidebar = document.querySelector('[data-sidebar]');
    const backdrop = document.querySelector('[data-mobile-backdrop]');
    const drawer = document.querySelector('[data-detail-drawer]');
    const isMobile = () => window.matchMedia('(max-width: 767px)').matches;
    const closeSidebar = () => { sidebar?.classList.remove('is-open'); if (backdrop) backdrop.hidden = true; };
    const setCollapsed = (collapsed) => {
        shell?.classList.toggle('is-sidebar-collapsed', collapsed);
        window.localStorage.setItem('oasis.workspace-v2.sidebar-collapsed', collapsed ? 'true' : 'false');
    };
    if (!isMobile() && window.localStorage.getItem('oasis.workspace-v2.sidebar-collapsed') === 'true') setCollapsed(true);
    document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => {
        if (isMobile()) {
            sidebar?.classList.add('is-open');
            if (backdrop) backdrop.hidden = false;
            return;
        }
        setCollapsed(!shell?.classList.contains('is-sidebar-collapsed'));
    });
    document.querySelector('[data-sidebar-close]')?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);
    document.querySelectorAll('.workspace-v2-nav-child, .workspace-v2-nav-link:not([data-process-toggle])').forEach((link) => link.addEventListener('click', closeSidebar));
    document.querySelector('[data-process-toggle]')?.addEventListener('click', (event) => {
        const menu = document.querySelector('[data-process-menu]');
        const expanded = event.currentTarget.getAttribute('aria-expanded') === 'true';
        event.currentTarget.setAttribute('aria-expanded', String(!expanded));
        menu?.classList.toggle('is-collapsed', expanded);
    });
    document.querySelectorAll('[data-detail-url]').forEach((element) => element.addEventListener('click', (event) => {
        if (element.tagName === 'A' && !element.closest('[data-detail-drawer]')) event.preventDefault();
        if (drawer) openDetail(element.dataset.detailUrl, drawer);
    }));
    document.querySelectorAll('[data-detail-url][tabindex="0"]').forEach((element) => element.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); if (drawer) openDetail(element.dataset.detailUrl, drawer); }
    }));
    document.querySelector('[data-drawer-close]')?.addEventListener('click', () => { drawer.hidden = true; drawer.setAttribute('aria-hidden', 'true'); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') { closeSidebar(); if (drawer && !drawer.hidden) { drawer.hidden = true; drawer.setAttribute('aria-hidden', 'true'); } } });
});
