(function () {
    'use strict';

    const config = window.LOP_CONFIG || {};

    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initJoin();
        initSpoiler();
        initTokens();
        initTimeline();
    });

    function post(action, params) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf_token', config.csrfToken);

        Object.keys(params || {}).forEach((key) => body.set(key, params[key]));

        return fetch('/lies-of-p/actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).then((response) => response.json());
    }

    function initTabs() {
        const tabButtons = document.querySelectorAll('.tab-btn[data-tab]');
        const tabPanels = document.querySelectorAll('[data-tab-panel]');

        tabButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-tab');

                tabButtons.forEach((btn) => btn.classList.toggle('is-active', btn === button));
                tabPanels.forEach((panel) => {
                    panel.classList.toggle('is-active', panel.getAttribute('data-tab-panel') === target);
                });
            });
        });
    }

    function initJoin() {
        const joinBtn = document.getElementById('lop-join-btn');

        if (!joinBtn) {
            return;
        }

        joinBtn.addEventListener('click', () => {
            joinBtn.disabled = true;

            post('join', {})
                .then((data) => {
                    if (data.ok) {
                        window.location.reload();
                    } else {
                        joinBtn.disabled = false;
                    }
                })
                .catch(() => {
                    joinBtn.disabled = false;
                });
        });
    }

    function initSpoiler() {
        const select = document.getElementById('lop-spoiler-select');

        if (!select) {
            return;
        }

        select.addEventListener('change', () => {
            post('set_spoiler', { level: select.value }).catch(() => {});
        });
    }

    function initTokens() {
        const form = document.getElementById('lop-token-form');

        if (form) {
            const nameInput = document.getElementById('lop-token-name');
            const reveal = document.getElementById('lop-token-reveal');
            const valueEl = document.getElementById('lop-token-value');
            const copyBtn = document.getElementById('lop-token-copy');

            form.addEventListener('submit', (event) => {
                event.preventDefault();

                post('create_token', { name: nameInput.value })
                    .then((data) => {
                        if (data.error || !data.token) {
                            return;
                        }

                        valueEl.textContent = data.token;
                        reveal.hidden = false;
                        nameInput.value = '';
                        addTokenRow(data.id, data.name);
                    })
                    .catch(() => {});
            });

            if (copyBtn) {
                copyBtn.addEventListener('click', () => {
                    const text = valueEl.textContent || '';

                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text).then(() => {
                            copyBtn.textContent = 'Kopiert!';
                            window.setTimeout(() => { copyBtn.textContent = 'Kopieren'; }, 1500);
                        });
                    }
                });
            }
        }

        // Delegate revoke clicks so newly added rows work too.
        const list = document.getElementById('lop-token-list');

        if (list) {
            list.addEventListener('click', (event) => {
                const btn = event.target.closest('.lop-token-revoke');

                if (!btn) {
                    return;
                }

                const row = btn.closest('.lop-token-row');
                const tokenId = row ? row.getAttribute('data-token-id') : null;

                if (!tokenId) {
                    return;
                }

                post('revoke_token', { token_id: tokenId }).then((data) => {
                    if (data.ok && row) {
                        row.remove();
                    }
                }).catch(() => {});
            });
        }
    }

    function addTokenRow(id, name) {
        const list = document.getElementById('lop-token-list');

        if (!list) {
            return;
        }

        const empty = document.getElementById('lop-token-empty');

        if (empty) {
            empty.remove();
        }

        const row = document.createElement('div');
        row.className = 'lop-token-row';
        row.setAttribute('data-token-id', String(id));

        const info = document.createElement('div');
        info.className = 'lop-token-info';

        const nameEl = document.createElement('span');
        nameEl.className = 'lop-token-name';
        nameEl.textContent = name;

        const meta = document.createElement('span');
        meta.className = 'muted';
        meta.textContent = 'gerade erstellt · tracker:read tracker:write';

        info.appendChild(nameEl);
        info.appendChild(meta);
        row.appendChild(info);
        list.appendChild(row);
    }

    function initTimeline() {
        const switcher = document.getElementById('lop-timeline-switch');
        const container = document.getElementById('lop-timeline');

        if (!switcher || !container) {
            return;
        }

        switcher.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-timeline-user]');

            if (!btn) {
                return;
            }

            const userId = btn.getAttribute('data-timeline-user');

            switcher.querySelectorAll('.lop-timeline-tab').forEach((tab) => {
                tab.classList.toggle('is-active', tab === btn);
            });

            container.innerHTML = '<p class="muted">Lade Timeline …</p>';

            fetch('/lies-of-p/actions.php?action=timeline&user_id=' + encodeURIComponent(userId), {
                credentials: 'same-origin',
            })
                .then((response) => response.json())
                .then((data) => renderTimeline(container, data.entries || []))
                .catch(() => {
                    container.innerHTML = '<p class="muted">Timeline konnte nicht geladen werden.</p>';
                });
        });
    }

    function renderTimeline(container, entries) {
        container.innerHTML = '';

        if (entries.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'muted';
            empty.textContent = 'Für diesen Spieler wurden noch keine Events aufgezeichnet.';
            container.appendChild(empty);

            return;
        }

        entries.forEach((entry) => {
            const row = document.createElement('div');
            row.className = 'lop-timeline-entry';

            const time = document.createElement('span');
            time.className = 'lop-timeline-time';
            time.textContent = entry.offset_label;
            row.appendChild(time);

            const emoji = document.createElement('span');
            emoji.className = 'lop-timeline-emoji';
            emoji.textContent = entry.emoji;
            row.appendChild(emoji);

            const bodyWrap = document.createElement('span');
            bodyWrap.className = 'lop-timeline-body';

            const title = document.createElement('span');
            title.className = 'lop-timeline-title';
            title.textContent = entry.title;
            bodyWrap.appendChild(title);

            if (entry.detail) {
                const detail = document.createElement('span');
                detail.className = 'lop-timeline-detail muted';
                detail.textContent = entry.detail;
                bodyWrap.appendChild(detail);
            }

            row.appendChild(bodyWrap);
            container.appendChild(row);
        });
    }
})();
