(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const nextBtn = document.getElementById('dashboard-clip-next');

        if (!nextBtn) {
            return;
        }

        const video = document.getElementById('dashboard-clip-video');
        const byline = document.getElementById('dashboard-clip-byline');

        nextBtn.addEventListener('click', () => {
            nextBtn.disabled = true;

            fetch('/clips/actions.php?action=random', { credentials: 'same-origin' })
                .then((response) => response.json())
                .then((data) => {
                    if (data.error || !data.clip) {
                        return;
                    }

                    const clip = data.clip;

                    video.pause();
                    video.src = clip.video_url;
                    video.load();

                    if (byline) {
                        byline.textContent = 'von ' + clip.uploader + ' · ' + clip.relative_time;
                    }

                    Object.keys(clip.reactions || {}).forEach((type) => {
                        const chip = document.querySelector('#dashboard-clip-reactions [data-count="' + type + '"]');

                        if (chip) {
                            const icon = chip.querySelector('svg');
                            chip.textContent = '';

                            if (icon) {
                                chip.appendChild(icon);
                            }

                            chip.appendChild(document.createTextNode(' ' + clip.reactions[type]));
                        }
                    });
                })
                .catch(() => {})
                .finally(() => {
                    nextBtn.disabled = false;
                });
        });
    });
})();
