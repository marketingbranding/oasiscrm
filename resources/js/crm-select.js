function initCustomSelects() {
    document.querySelectorAll('.select-wrapper').forEach(function(wrapper) {
        if (wrapper.__sw) return;
        wrapper.__sw = true;
        const display = wrapper.querySelector('.select-display');
        const textEl = display ? display.querySelector('.select-text') : null;
        const arrow = display ? display.querySelector('.select-arrow') : null;
        const select = wrapper.querySelector('select');
        if (!display || !textEl || !arrow || !select) return;

        let dropdown = wrapper.querySelector('.select-dropdown');

        if (!dropdown) {
            dropdown = document.createElement('div');
            dropdown.className = 'select-dropdown';
            dropdown.style.cssText = 'display:none;position:absolute;top:100%;left:0;z-index:50;border:2px solid #000;background:#fff;width:100%;max-height:200px;overflow-y:auto';
            dropdown.innerHTML =
                '<input type="text" class="select-search" placeholder="Cari..." style="width:100%;border:none;border-bottom:1px solid #ddd;padding:6px 12px;font-size:13px;font-family:\'Times New Roman\';outline:none;box-sizing:border-box">';
            const list = document.createElement('ul');
            list.className = 'select-options';
            list.style.cssText = 'list-style:none;margin:0;padding:0';
            dropdown.appendChild(list);
            wrapper.appendChild(dropdown);
        }

        const search = dropdown.querySelector('.select-search');
        const list = dropdown.querySelector('.select-options');
        if (!search || !list) return;

        function populateOptions() {
            list.innerHTML = '';
            Array.from(select.options).forEach(function(opt) {
                const li = document.createElement('li');
                li.dataset.value = opt.value;
                li.textContent = opt.text;
                li.className = 'select-li';
                if (opt.selected) li.classList.add('s-selected');
                list.appendChild(li);
            });
        }

        function syncDisplay() {
            const idx = select.selectedIndex;
            textEl.textContent = idx > 0 ? select.options[idx].text : (select.options[0] ? select.options[0].text : '\u2014 Pilih \u2014');
        }

        function openDropdown() {
            populateOptions();
            dropdown.style.display = 'block';
            arrow.textContent = '\u25B2';
            search.value = '';
            search.focus();
            Array.from(list.children).forEach(function(li) { li.style.display = ''; });
        }

        function closeDropdown() {
            dropdown.style.display = 'none';
            arrow.textContent = '\u25BC';
        }

        function selectOption(li) {
            if (!li || !li.dataset.value) return;
            list.querySelectorAll('li').forEach(function(l) { l.classList.remove('s-selected'); });
            li.classList.add('s-selected');
            textEl.textContent = li.textContent;
            select.value = li.dataset.value;
            closeDropdown();
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        display.addEventListener('click', function(e) {
            e.stopPropagation();
            if (dropdown.style.display !== 'none') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        search.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            Array.from(list.children).forEach(function(li) {
                li.style.display = li.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });

        search.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const visible = list.querySelector('li:not([style*="display: none"])');
                if (visible) selectOption(visible);
            }
            if (e.key === 'Escape') closeDropdown();
        });

        list.addEventListener('click', function(e) {
            const li = e.target.closest('li');
            if (li) selectOption(li);
        });

        list.addEventListener('mouseover', function(e) {
            const li = e.target.closest('li');
            if (li) {
                list.querySelectorAll('li').forEach(function(l) { l.classList.remove('s-hover'); });
                li.classList.add('s-hover');
            }
        });

        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) {
                closeDropdown();
            }
        });

        syncDisplay();
        const sw = display.offsetWidth;
        if (sw > 0) dropdown.style.width = sw + 'px';
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCustomSelects);
} else {
    initCustomSelects();
}
