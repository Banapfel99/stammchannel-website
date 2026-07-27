(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initClipPlayer();
        initUploadForm();
    });

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

    function initClipPlayer() {
        const root = document.getElementById('clip-player');

        if (!root) {
            return;
        }

        const video = document.getElementById('clip-video');
        const meta = document.getElementById('clip-meta');
        const emptyState = document.getElementById('clip-empty-state');
        const loadingState = document.getElementById('clip-loading-state');
        const errorState = document.getElementById('clip-error-state');
        const titleEl = document.getElementById('clip-title');
        const subEl = document.getElementById('clip-sub');
        const nextBtn = document.getElementById('clip-next-btn');
        const reactionButtons = document.querySelectorAll('.reaction-btn[data-reaction]');

        let currentClipId = null;
        let viewRecorded = false;

        function setState(state) {
            emptyState.hidden = state !== 'empty';
            loadingState.hidden = state !== 'loading';
            errorState.hidden = state !== 'error';
            meta.hidden = state !== 'ready';
            video.hidden = state !== 'ready';
        }

        function updateReactionUI(reactions, userReactions) {
            reactionButtons.forEach((button) => {
                const type = button.getAttribute('data-reaction');
                const countEl = button.querySelector('[data-count="' + type + '"]');

                if (countEl) {
                    countEl.textContent = String((reactions && reactions[type]) || 0);
                }

                button.classList.toggle('is-active', Array.isArray(userReactions) && userReactions.indexOf(type) !== -1);
            });
        }

        function loadClip(clip) {
            if (!clip) {
                setState('empty');
                currentClipId = null;

                return;
            }

            currentClipId = clip.id;
            viewRecorded = false;

            video.pause();
            video.src = clip.video_url;
            video.load();

            titleEl.textContent = clip.title;
            subEl.textContent = 'von ' + clip.uploader + ' · ' + clip.relative_time;

            updateReactionUI(clip.reactions, clip.user_reactions);

            setState('ready');
        }

        function fetchRandomClip() {
            setState('loading');

            fetch('/clips/actions.php?action=random', { credentials: 'same-origin' })
                .then((response) => response.json())
                .then((data) => {
                    if (data.error) {
                        setState('error');

                        return;
                    }

                    loadClip(data.clip);
                })
                .catch(() => setState('error'));
        }

        video.addEventListener('play', () => {
            if (viewRecorded || !currentClipId) {
                return;
            }

            viewRecorded = true;

            postForm('/clips/actions.php', {
                action: 'view',
                clip_id: currentClipId,
                csrf_token: window.CLIPS_CONFIG.csrfToken,
            }).catch(() => {});
        });

        nextBtn.addEventListener('click', fetchRandomClip);

        reactionButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (!currentClipId) {
                    return;
                }

                const type = button.getAttribute('data-reaction');

                postForm('/clips/actions.php', {
                    action: 'react',
                    clip_id: currentClipId,
                    reaction_type: type,
                    csrf_token: window.CLIPS_CONFIG.csrfToken,
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.ok) {
                            return;
                        }

                        button.classList.toggle('is-active', data.active);

                        Object.keys(data.reactions || {}).forEach((reactionType) => {
                            const countEl = document.querySelector('[data-count="' + reactionType + '"]');

                            if (countEl) {
                                countEl.textContent = String(data.reactions[reactionType]);
                            }
                        });
                    })
                    .catch(() => {});
            });
        });

        document.querySelectorAll('.clip-card').forEach((card) => {
            card.addEventListener('click', (event) => {
                if (event.target.closest('form')) {
                    return;
                }

                const clipId = parseInt(card.getAttribute('data-clip-id'), 10);
                const cardVideo = card.querySelector('.clip-card-video');

                if (!clipId || !cardVideo) {
                    return;
                }

                loadClip({
                    id: clipId,
                    title: card.querySelector('h4') ? card.querySelector('h4').textContent : '',
                    uploader: '',
                    relative_time: '',
                    video_url: cardVideo.src,
                    reactions: { funny: 0, nice: 0, rip: 0 },
                    user_reactions: [],
                });

                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        fetchRandomClip();
    }

    function postForm(url, params) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString(),
        });
    }

    function initUploadForm() {
        const form = document.getElementById('clip-upload-form');

        if (!form) {
            return;
        }

        const progressWrap = document.getElementById('clip-upload-progress');
        const progressBar = document.getElementById('clip-upload-progress-bar');
        const progressLabel = document.getElementById('clip-upload-progress-label');
        const submitBtn = document.getElementById('clip-upload-submit');

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();

            submitBtn.disabled = true;
            progressWrap.hidden = false;
            progressBar.style.width = '0%';
            progressLabel.textContent = 'Wird hochgeladen … 0%';

            xhr.upload.addEventListener('progress', (progressEvent) => {
                if (!progressEvent.lengthComputable) {
                    return;
                }

                const percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                progressBar.style.width = percent + '%';
                progressLabel.textContent = percent < 100
                    ? ('Wird hochgeladen … ' + percent + '%')
                    : 'Wird verarbeitet (FFmpeg) …';
            });

            xhr.addEventListener('load', () => {
                if (xhr.responseURL) {
                    window.location.href = xhr.responseURL;

                    return;
                }

                window.location.reload();
            });

            xhr.addEventListener('error', () => {
                submitBtn.disabled = false;
                progressLabel.textContent = 'Upload fehlgeschlagen. Bitte erneut versuchen.';
            });

            xhr.open('POST', form.action, true);
            xhr.send(formData);
        });
    }
})();
