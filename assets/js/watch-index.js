(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('watch-create-form');

        if (!form) {
            return;
        }

        const nameInput = document.getElementById('watch-create-name');
        const errorEl = document.getElementById('watch-create-error');
        const csrfToken = form.querySelector('input[name="csrf_token"]').value;

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (errorEl) errorEl.hidden = true;

            const body = new URLSearchParams();
            body.set('action', 'create');
            body.set('csrf_token', csrfToken);
            body.set('name', nameInput.value);

            fetch('/watch/actions.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.error || !data.room_id) {
                        if (errorEl) {
                            errorEl.textContent = data.error || 'Raum konnte nicht erstellt werden.';
                            errorEl.hidden = false;
                        }
                        return;
                    }

                    window.location.href = '/watch/room.php?id=' + data.room_id;
                })
                .catch(() => {
                    if (errorEl) {
                        errorEl.textContent = 'Netzwerkfehler.';
                        errorEl.hidden = false;
                    }
                });
        });
    });
})();
