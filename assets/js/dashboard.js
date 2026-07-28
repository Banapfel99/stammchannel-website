(function () {
    'use strict';

    // Makes an entire widget card navigable to its detail page. Some widgets
    // (StammClips, Watch Together, Lies of P) are containers with their own
    // interactive controls (video, buttons, links, forms), so they cannot be a
    // single <a>. Instead we treat a click anywhere on the card as navigation,
    // while leaving genuine interactive elements to do their own thing.
    document.addEventListener('DOMContentLoaded', () => {
        const cards = document.querySelectorAll('.widget-card[data-href]');

        cards.forEach((card) => {
            const href = card.getAttribute('data-href');

            if (!href) {
                return;
            }

            card.classList.add('is-clickable');

            card.addEventListener('click', (event) => {
                // Ignore clicks that landed on (or inside) an interactive
                // element — those already have their own behaviour.
                if (event.target.closest('a, button, video, audio, input, select, textarea, label, form')) {
                    return;
                }

                window.location.href = href;
            });
        });
    });
})();
