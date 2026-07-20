(function () {
    'use strict';

    const playerRoot = document.getElementById('music-player');

    if (!playerRoot) {
        return;
    }

    const playlistId = playerRoot.dataset.playlistId;
    const audio = document.getElementById('audio-element');
    const nowPlayingTitle = document.getElementById('now-playing-title');
    const nowPlayingUploader = document.getElementById('now-playing-uploader');
    const progressBar = document.getElementById('progress-bar');
    const syncStatus = document.getElementById('sync-status');
    const syncStatusText = document.getElementById('sync-status-text');
    const btnPlay = document.getElementById('btn-play');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const btnShuffle = document.getElementById('btn-shuffle');
    const btnRepeat = document.getElementById('btn-repeat');
    const volumeBar = document.getElementById('volume-bar');
    const vinylCover = document.getElementById('vinyl-cover');
    const vinylLabel = document.getElementById('vinyl-label');
    const listenersList = document.getElementById('listeners-list');
    const activityList = document.getElementById('activity-list');
    const trackPosition = document.getElementById('track-position');
    const timeCurrent = document.getElementById('time-current');
    const timeDuration = document.getElementById('time-duration');

    const tracks = JSON.parse(document.getElementById('track-data').textContent || '[]');
    const csrfToken = window.MUSIC_CSRF_TOKEN;
    const avatarColors = ['#ff8a3d', '#ff6b57', '#ffb454', '#34d399', '#22c1ff', '#b16bff'];

    let repeat = false;

    let currentTrackId = null;
    let isPlaying = false;
    let shuffle = false;
    let suppressSync = false;
    let hasRecordedPlayFor = null;
    let lastAppliedUpdatedAt = null;

    function findTrackIndex(trackId) {
        return tracks.findIndex((t) => t.id === trackId);
    }

    function trackAudioUrl(trackId) {
        return '/music/file.php?type=audio&id=' + encodeURIComponent(trackId);
    }

    function formatTime(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) {
            return '0:00';
        }

        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);

        return mins + ':' + String(secs).padStart(2, '0');
    }

    function setNowPlaying(trackId) {
        const track = tracks.find((t) => t.id === trackId);
        nowPlayingTitle.textContent = track ? track.title : 'Kein Titel ausgewählt';
        nowPlayingUploader.textContent = track
            ? 'Hochgeladen von ' + track.uploader
            : 'Wähle einen Titel aus der Liste';

        if (track && track.cover) {
            vinylCover.src = track.cover;
            vinylCover.hidden = false;
            vinylLabel.hidden = true;
        } else {
            vinylCover.hidden = true;
            vinylCover.src = '';
            vinylLabel.hidden = false;
        }

        if (trackPosition) {
            const index = findTrackIndex(trackId);
            trackPosition.textContent = index === -1 || tracks.length === 0
                ? ''
                : 'Titel ' + (index + 1) + ' von ' + tracks.length;
        }
    }

    function initVolume() {
        const stored = Number(window.localStorage.getItem('music-player-volume'));
        const initial = Number.isFinite(stored) && stored >= 0 && stored <= 100 ? stored : 80;
        volumeBar.value = String(initial);
        audio.volume = initial / 100;
    }

    function renderListeners(listeners) {
        if (!Array.isArray(listeners) || listeners.length === 0) {
            listenersList.innerHTML = '';
            listenersList.hidden = true;
            return;
        }

    function avatarColorFor(name) {
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
        }
        return avatarColors[hash % avatarColors.length];
    }

    function renderListeners(listeners) {
        if (!Array.isArray(listeners) || listeners.length === 0) {
            listenersList.innerHTML = '';
            return;
        }

        const maxVisible = 4;
        const visible = listeners.slice(0, maxVisible);
        const overflow = listeners.length - visible.length;

        listenersList.innerHTML = visible
            .map((name) => {
                const safeName = String(name).replace(/[<>&]/g, '');
                const initial = safeName.charAt(0).toUpperCase() || '?';
                return '<span class="listener-avatar" title="' + safeName + ' hört mit" style="background:' + avatarColorFor(safeName) + '">'
                    + initial
                    + '</span>';
            })
            .join('') + (overflow > 0 ? '<span class="listener-avatar listener-avatar-overflow">+' + overflow + '</span>' : '');
    }

    function formatRelativeTime(dateString) {
        const then = new Date(dateString.replace(' ', 'T') + 'Z').getTime();
        const diffMinutes = Math.max(0, Math.round((Date.now() - then) / 60000));

        if (diffMinutes < 1) {
            return 'gerade eben';
        }

        return 'vor ' + diffMinutes + ' Min.';
    }

    function renderActivity(activity) {
        if (!activityList) {
            return;
        }

        if (!Array.isArray(activity) || activity.length === 0) {
            activityList.innerHTML = '<li class="muted">Noch keine Aktivität.</li>';
            return;
        }

        activityList.innerHTML = activity
            .map((entry) => {
                const safeName = String(entry.username).replace(/[<>&]/g, '');
                return '<li><span class="activity-dot"></span>'
                    + '<span class="activity-text"><strong>' + safeName + '</strong> ist beigetreten</span>'
                    + '<span class="activity-time">' + formatRelativeTime(entry.last_seen) + '</span></li>';
            })
            .join('');
    }

    function loadTrack(trackId, autoplay) {
        if (trackId === null) {
            return;
        }

        currentTrackId = trackId;
        audio.src = trackAudioUrl(trackId);
        setNowPlaying(trackId);
        hasRecordedPlayFor = null;

        if (autoplay) {
            audio.play().catch(() => {});
        }
    }

    function postAction(action, params) {
        const body = new URLSearchParams(Object.assign({
            action: action,
            csrf_token: csrfToken,
            playlist_id: playlistId,
        }, params));

        return fetch('/music/actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
    }

    function broadcastState() {
        postAction('update', {
            current_track_id: currentTrackId ?? '',
            position_seconds: audio.currentTime || 0,
            is_playing: isPlaying ? '1' : '0',
            shuffle: shuffle ? '1' : '0',
        });
    }

    function recordPlayIfNeeded() {
        if (currentTrackId !== null && hasRecordedPlayFor !== currentTrackId) {
            hasRecordedPlayFor = currentTrackId;
            postAction('record_play', { track_id: currentTrackId });
        }
    }

    function playPause() {
        if (audio.paused) {
            audio.play().catch(() => {});
        } else {
            audio.pause();
        }
    }

    function pickNextTrackId(direction) {
        if (tracks.length === 0) {
            return null;
        }

        if (shuffle && direction > 0) {
            const others = tracks.filter((t) => t.id !== currentTrackId);
            const pool = others.length > 0 ? others : tracks;
            return pool[Math.floor(Math.random() * pool.length)].id;
        }

        const index = findTrackIndex(currentTrackId);
        const nextIndex = index === -1
            ? 0
            : (index + direction + tracks.length) % tracks.length;

        return tracks[nextIndex].id;
    }

    audio.addEventListener('play', () => {
        isPlaying = true;
        btnPlay.classList.add('is-playing');
        playerRoot.classList.add('is-playing');
        recordPlayIfNeeded();

        if (!suppressSync) {
            broadcastState();
        }
    });

    audio.addEventListener('pause', () => {
        isPlaying = false;
        btnPlay.classList.remove('is-playing');
        playerRoot.classList.remove('is-playing');

        if (!suppressSync) {
            broadcastState();
        }
    });

    audio.addEventListener('timeupdate', () => {
        if (audio.duration > 0) {
            progressBar.value = String((audio.currentTime / audio.duration) * 100);
        }

        timeCurrent.textContent = formatTime(audio.currentTime);
        timeDuration.textContent = formatTime(audio.duration);
    });

    audio.addEventListener('loadedmetadata', () => {
        timeDuration.textContent = formatTime(audio.duration);
    });

    audio.addEventListener('ended', () => {
        if (repeat) {
            loadTrack(currentTrackId, true);
            broadcastState();
            return;
        }

        const nextId = pickNextTrackId(1);
        loadTrack(nextId, true);
        broadcastState();
    });

    progressBar.addEventListener('input', () => {
        if (audio.duration > 0) {
            audio.currentTime = (Number(progressBar.value) / 100) * audio.duration;
            broadcastState();
        }
    });

    btnPlay.addEventListener('click', playPause);

    btnNext.addEventListener('click', () => {
        loadTrack(pickNextTrackId(1), true);
        broadcastState();
    });

    btnPrev.addEventListener('click', () => {
        loadTrack(pickNextTrackId(-1), true);
        broadcastState();
    });

    btnShuffle.addEventListener('click', () => {
        shuffle = !shuffle;
        btnShuffle.classList.toggle('active', shuffle);
        broadcastState();
    });

    if (btnRepeat) {
        btnRepeat.addEventListener('click', () => {
            repeat = !repeat;
            btnRepeat.classList.toggle('active', repeat);
        });
    }

    volumeBar.addEventListener('input', () => {
        const value = Number(volumeBar.value);
        audio.volume = value / 100;
        window.localStorage.setItem('music-player-volume', String(value));
    });

    document.querySelectorAll('.btn-play-track').forEach((button) => {
        button.addEventListener('click', () => {
            const trackId = Number(button.dataset.trackId);
            loadTrack(trackId, true);
            broadcastState();
        });
    });

    function applyRemoteState(room) {
        if (room.updated_at === lastAppliedUpdatedAt) {
            return;
        }

        lastAppliedUpdatedAt = room.updated_at;
        suppressSync = true;

        if (room.current_track_id !== currentTrackId) {
            loadTrack(room.current_track_id, false);
        }

        shuffle = room.shuffle;
        btnShuffle.classList.toggle('active', shuffle);

        const elapsedSinceUpdate = (new Date(room.server_time.replace(' ', 'T') + 'Z') -
            new Date(room.updated_at.replace(' ', 'T') + 'Z')) / 1000;

        const estimatedPosition = room.is_playing
            ? room.position_seconds + Math.max(0, elapsedSinceUpdate)
            : room.position_seconds;

        if (Math.abs(audio.currentTime - estimatedPosition) > 1.5) {
            audio.currentTime = estimatedPosition;
        }

        if (room.is_playing && audio.paused) {
            audio.play().catch(() => {});
        } else if (!room.is_playing && !audio.paused) {
            audio.pause();
        }

        syncStatus.classList.remove('is-error');
        if (syncStatusText) {
            syncStatusText.textContent = 'Synchronisiert';
        }
        suppressSync = false;
    }

    function pollState() {
        fetch('/music/actions.php?action=state&playlist_id=' + encodeURIComponent(playlistId))
            .then((response) => response.json())
            .then((data) => {
                if (data.room) {
                    applyRemoteState(data.room);
                }

                renderListeners(data.listeners);
                renderActivity(data.activity);
            })
            .catch(() => {
                syncStatus.classList.add('is-error');
                if (syncStatusText) {
                    syncStatusText.textContent = 'Sync-Fehler';
                }
            });
    }

    function initMenus() {
        document.addEventListener('click', (event) => {
            const toggle = event.target.closest('.menu-toggle');

            if (toggle) {
                const dropdown = toggle.parentElement.querySelector('.menu-dropdown');

                if (!dropdown) {
                    return;
                }

                const willOpen = dropdown.hidden;

                document.querySelectorAll('.menu-dropdown').forEach((el) => {
                    el.hidden = true;
                });
                document.querySelectorAll('.menu-toggle').forEach((el) => {
                    el.setAttribute('aria-expanded', 'false');
                });

                dropdown.hidden = !willOpen;
                toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                event.stopPropagation();
                return;
            }

            if (!event.target.closest('.menu-dropdown')) {
                document.querySelectorAll('.menu-dropdown').forEach((el) => {
                    el.hidden = true;
                });
                document.querySelectorAll('.menu-toggle').forEach((el) => {
                    el.setAttribute('aria-expanded', 'false');
                });
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.querySelectorAll('.menu-dropdown').forEach((el) => {
                    el.hidden = true;
                });
                document.querySelectorAll('.menu-toggle').forEach((el) => {
                    el.setAttribute('aria-expanded', 'false');
                });
            }
        });
    }

    function initTabs() {
        document.querySelectorAll('.tab-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.dataset.tab;

                document.querySelectorAll('.tab-btn').forEach((b) => {
                    b.classList.toggle('is-active', b === button);
                });

                document.querySelectorAll('.tab-panel').forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.tabPanel === target);
                });
            });
        });
    }

    initTabs();
    initMenus();
    initVolume();
    pollState();
    setInterval(pollState, 2000);
})();
