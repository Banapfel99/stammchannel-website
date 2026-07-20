(function () {
    'use strict';

    const playerRoot = document.getElementById('music-player');

    if (!playerRoot) {
        return;
    }

    const playlistId = playerRoot.dataset.playlistId;
    const audio = document.getElementById('audio-element');
    const nowPlayingTitle = document.getElementById('now-playing-title');
    const progressBar = document.getElementById('progress-bar');
    const syncStatus = document.getElementById('sync-status');
    const btnPlay = document.getElementById('btn-play');
    const btnPrev = document.getElementById('btn-prev');
    const btnNext = document.getElementById('btn-next');
    const btnShuffle = document.getElementById('btn-shuffle');
    const volumeBar = document.getElementById('volume-bar');
    const vinylCover = document.getElementById('vinyl-cover');
    const vinylLabel = document.getElementById('vinyl-label');
    const listenersList = document.getElementById('listeners-list');

    const tracks = JSON.parse(document.getElementById('track-data').textContent || '[]');
    const csrfToken = window.MUSIC_CSRF_TOKEN;

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

    function setNowPlaying(trackId) {
        const track = tracks.find((t) => t.id === trackId);
        nowPlayingTitle.textContent = track
            ? track.title + ' — hochgeladen von ' + track.uploader
            : 'Kein Titel ausgewählt';

        if (track && track.cover) {
            vinylCover.src = track.cover;
            vinylCover.hidden = false;
            vinylLabel.hidden = true;
        } else {
            vinylCover.hidden = true;
            vinylCover.src = '';
            vinylLabel.hidden = false;
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

        listenersList.hidden = false;
        listenersList.innerHTML = listeners
            .map((name) => '<span class="listener-chip">' + name.replace(/[<>&]/g, '') + '</span>')
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
    });

    audio.addEventListener('ended', () => {
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

        syncStatus.textContent = 'Synchronisiert';
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
            })
            .catch(() => {
                syncStatus.textContent = 'Synchronisierung fehlgeschlagen';
            });
    }

    initVolume();
    pollState();
    setInterval(pollState, 2000);
})();
