(function () {
    'use strict';

    const config = window.WATCH_ROOM_CONFIG;

    if (!config) {
        return;
    }

    // ----- Tunables --------------------------------------------------------
    // Only correct a guest's playback head when it drifts beyond this many
    // seconds from the authoritative server position. Prevents constant micro
    // seeking on tiny differences.
    const DRIFT_TOLERANCE_SECONDS = 1.5;

    // How often the host reports its live position to the server while playing.
    const HOST_BROADCAST_INTERVAL_MS = 5000;

    // ----- State -----------------------------------------------------------
    let player = null;
    let playerReady = false;
    // When true, we are applying remote state and must ignore local player
    // events so guests never echo playback commands back to the server.
    let applyingRemote = false;
    let currentYoutubeId = config.state.currentYoutubeId || config.state.current_youtube_id || null;
    let hostBroadcastTimer = null;

    const els = {
        player: document.getElementById('watch-player'),
        playerEmpty: document.getElementById('watch-player-empty'),
        nowPlaying: document.getElementById('watch-now-playing'),
        nowTitle: document.getElementById('watch-now-title'),
        hostName: document.getElementById('watch-host-name'),
        youHostBadge: document.getElementById('watch-you-host-badge'),
        viewerCount: document.getElementById('watch-viewer-count'),
        queue: document.getElementById('watch-queue'),
        queueEmpty: document.getElementById('watch-queue-empty'),
        chat: document.getElementById('watch-chat'),
        addForm: document.getElementById('watch-add-form'),
        addUrl: document.getElementById('watch-add-url'),
        addError: document.getElementById('watch-add-error'),
        chatForm: document.getElementById('watch-chat-form'),
        chatInput: document.getElementById('watch-chat-input'),
        leaveBtn: document.getElementById('watch-leave-btn')
    };

    // ----- Helpers ---------------------------------------------------------
    function post(action, params) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf_token', config.csrfToken);
        body.set('room_id', String(config.roomId));

        Object.keys(params || {}).forEach((key) => body.set(key, params[key]));

        return fetch('/watch/actions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then((response) => response.json());
    }

    function relativeTimeShort(iso) {
        const date = new Date((iso || '').replace(' ', 'T'));

        if (isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    }

    // ----- YouTube player --------------------------------------------------
    window.onYouTubeIframeAPIReady = function () {
        player = new YT.Player('watch-player', {
            width: '100%',
            height: '100%',
            playerVars: {
                autoplay: 0,
                controls: config.isHost ? 1 : 0,
                disablekb: config.isHost ? 0 : 1,
                modestbranding: 1,
                rel: 0,
                playsinline: 1
            },
            events: {
                onReady: onPlayerReady,
                onStateChange: onPlayerStateChange
            }
        });
    };

    function onPlayerReady() {
        playerReady = true;
        applyState(config.state, true);
    }

    function onPlayerStateChange(event) {
        if (applyingRemote || !config.isHost) {
            return;
        }

        // Host: reflect local play/pause/seek to the authoritative server.
        if (event.data === YT.PlayerState.PLAYING) {
            broadcastPlayback('playing');
            startHostBroadcast();
        } else if (event.data === YT.PlayerState.PAUSED) {
            broadcastPlayback('paused');
            stopHostBroadcast();
        } else if (event.data === YT.PlayerState.ENDED) {
            stopHostBroadcast();
            post('skip', {});
        }
    }

    function broadcastPlayback(state) {
        if (!playerReady) {
            return;
        }

        let position = 0;

        try {
            position = player.getCurrentTime() || 0;
        } catch (e) {
            position = 0;
        }

        post('playback', { state: state, position: position.toFixed(3) });
    }

    function startHostBroadcast() {
        stopHostBroadcast();
        hostBroadcastTimer = window.setInterval(() => {
            if (config.isHost && playerReady) {
                broadcastPlayback('playing');
            }
        }, HOST_BROADCAST_INTERVAL_MS);
    }

    function stopHostBroadcast() {
        if (hostBroadcastTimer !== null) {
            window.clearInterval(hostBroadcastTimer);
            hostBroadcastTimer = null;
        }
    }

    // ----- Applying authoritative state -----------------------------------
    function applyState(state, force) {
        if (!playerReady || !player) {
            return;
        }

        const youtubeId = state.current_youtube_id;

        // Empty room / no current video.
        if (!youtubeId) {
            currentYoutubeId = null;

            try {
                player.stopVideo();
            } catch (e) { /* ignore */ }

            if (els.playerEmpty) els.playerEmpty.hidden = false;
            if (els.nowPlaying) els.nowPlaying.hidden = true;
            return;
        }

        if (els.playerEmpty) els.playerEmpty.hidden = true;
        if (els.nowPlaying) els.nowPlaying.hidden = false;
        if (els.nowTitle) els.nowTitle.textContent = state.current_title || 'YouTube-Video';

        applyingRemote = true;

        const videoChanged = youtubeId !== currentYoutubeId || force;

        if (videoChanged) {
            currentYoutubeId = youtubeId;

            if (state.playback_state === 'playing') {
                player.loadVideoById({ videoId: youtubeId, startSeconds: state.position || 0 });
            } else {
                player.cueVideoById({ videoId: youtubeId, startSeconds: state.position || 0 });
            }
        } else {
            syncPlaybackToState(state);
        }

        // Release the suppression shortly after, once the player settled.
        window.setTimeout(() => { applyingRemote = false; }, 400);

        if (config.isHost && state.playback_state === 'playing') {
            startHostBroadcast();
        }
    }

    function syncPlaybackToState(state) {
        let localTime = 0;

        try {
            localTime = player.getCurrentTime() || 0;
        } catch (e) {
            localTime = 0;
        }

        const drift = Math.abs(localTime - (state.position || 0));

        if (drift > DRIFT_TOLERANCE_SECONDS) {
            player.seekTo(state.position || 0, true);
        }

        const playerState = player.getPlayerState();

        if (state.playback_state === 'playing' && playerState !== YT.PlayerState.PLAYING) {
            player.playVideo();
        } else if (state.playback_state === 'paused' && playerState === YT.PlayerState.PLAYING) {
            player.pauseVideo();
        }
    }

    // ----- Queue rendering -------------------------------------------------
    function renderQueue(items) {
        if (!els.queue) {
            return;
        }

        els.queue.querySelectorAll('.watch-queue-item').forEach((node) => node.remove());

        if (els.queueEmpty) {
            els.queueEmpty.hidden = items.length > 0;
        }

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'watch-queue-item';
            row.dataset.itemId = String(item.id);

            if (Number(item.id) === Number(config.state.current_item_id)) {
                row.classList.add('is-current');
            }

            const thumb = document.createElement('img');
            thumb.className = 'watch-queue-thumb';
            thumb.loading = 'lazy';
            thumb.src = 'https://i.ytimg.com/vi/' + encodeURIComponent(item.youtube_id) + '/mqdefault.jpg';
            thumb.alt = '';
            row.appendChild(thumb);

            const body = document.createElement('div');
            body.className = 'watch-queue-body';

            const title = document.createElement('span');
            title.className = 'watch-queue-title';
            title.textContent = item.title || 'YouTube-Video';
            body.appendChild(title);

            const meta = document.createElement('span');
            meta.className = 'watch-queue-meta muted';
            meta.textContent = item.added_by_username ? 'von ' + item.added_by_username : '';
            body.appendChild(meta);

            row.appendChild(body);

            const actions = document.createElement('div');
            actions.className = 'watch-queue-actions';

            if (config.isHost) {
                const playBtn = document.createElement('button');
                playBtn.type = 'button';
                playBtn.className = 'btn-icon-ghost-sm';
                playBtn.title = 'Jetzt abspielen';
                playBtn.textContent = '▶';
                playBtn.addEventListener('click', () => post('set_current', { item_id: item.id }));
                actions.appendChild(playBtn);
            }

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn-icon-ghost-sm';
            removeBtn.title = 'Entfernen';
            removeBtn.textContent = '✕';
            removeBtn.addEventListener('click', () => post('remove_queue', { item_id: item.id }));
            actions.appendChild(removeBtn);

            row.appendChild(actions);
            els.queue.appendChild(row);
        });
    }

    // ----- Chat rendering --------------------------------------------------
    function appendChatMessage(message) {
        if (!els.chat) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'watch-chat-msg';

        const author = document.createElement('span');
        author.className = 'watch-chat-author';
        author.textContent = message.username || 'Unbekannt';
        row.appendChild(author);

        const time = document.createElement('span');
        time.className = 'watch-chat-time muted';
        time.textContent = relativeTimeShort(message.created_at);
        row.appendChild(time);

        const body = document.createElement('div');
        body.className = 'watch-chat-body';
        body.textContent = message.body; // textContent = XSS-safe
        row.appendChild(body);

        const atBottom = els.chat.scrollHeight - els.chat.scrollTop - els.chat.clientHeight < 40;
        els.chat.appendChild(row);

        if (atBottom) {
            els.chat.scrollTop = els.chat.scrollHeight;
        }
    }

    // ----- Presence --------------------------------------------------------
    function applyPresence(data) {
        if (els.viewerCount) {
            els.viewerCount.textContent = String(data.viewer_count);
        }

        const nowHost = Number(data.host_id) === Number(config.userId);

        if (nowHost !== config.isHost) {
            // Host changed to/from us — reload so player controls & permissions
            // are re-rendered cleanly from the server.
            window.location.reload();
        }
    }

    // ----- SSE subscription ------------------------------------------------
    function connectStream() {
        const url = '/watch/stream.php?room_id=' + config.roomId
            + '&last_message_id=' + config.lastMessageId;
        const source = new EventSource(url);

        source.addEventListener('state', (event) => {
            const state = JSON.parse(event.data);
            config.state = state;

            if (els.hostName && state.host_username) {
                els.hostName.textContent = state.host_username;
            }

            applyState(state, false);
        });

        source.addEventListener('queue', (event) => {
            const data = JSON.parse(event.data);
            renderQueue(data.items || []);
        });

        source.addEventListener('presence', (event) => {
            applyPresence(JSON.parse(event.data));
        });

        source.addEventListener('chat', (event) => {
            const message = JSON.parse(event.data);
            config.lastMessageId = Math.max(config.lastMessageId, Number(message.id));
            appendChatMessage(message);
        });

        source.addEventListener('room_closed', () => {
            source.close();
            window.location.href = '/watch/';
        });

        source.onerror = function () {
            // EventSource auto-reconnects; nothing to do.
        };

        return source;
    }

    // ----- Wiring ----------------------------------------------------------
    function initTabs() {
        const tabs = document.querySelectorAll('[data-watch-tab]');
        const panels = document.querySelectorAll('[data-watch-panel]');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const name = tab.dataset.watchTab;
                tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
                panels.forEach((p) => p.classList.toggle('is-active', p.dataset.watchPanel === name));
            });
        });
    }

    if (els.addForm) {
        els.addForm.addEventListener('submit', (event) => {
            event.preventDefault();

            if (els.addError) els.addError.hidden = true;

            post('add_queue', { url: els.addUrl.value }).then((data) => {
                if (data.error) {
                    if (els.addError) {
                        els.addError.textContent = data.error;
                        els.addError.hidden = false;
                    }
                    return;
                }

                els.addUrl.value = '';
            });
        });
    }

    if (els.chatForm) {
        els.chatForm.addEventListener('submit', (event) => {
            event.preventDefault();

            const body = els.chatInput.value.trim();

            if (body === '') {
                return;
            }

            post('chat', { body: body }).then((data) => {
                if (!data.error) {
                    els.chatInput.value = '';
                }
            });
        });
    }

    if (els.leaveBtn) {
        els.leaveBtn.addEventListener('click', () => {
            post('leave', {}).finally(() => {
                window.location.href = '/watch/';
            });
        });
    }

    // Best-effort leave notification when closing the tab.
    window.addEventListener('pagehide', () => {
        const body = new URLSearchParams();
        body.set('action', 'leave');
        body.set('csrf_token', config.csrfToken);
        body.set('room_id', String(config.roomId));

        if (navigator.sendBeacon) {
            navigator.sendBeacon('/watch/actions.php', body);
        }
    });

    // ----- Boot ------------------------------------------------------------
    initTabs();
    renderQueue(config.queue || []);
    (config.messages || []).forEach(appendChatMessage);
    connectStream();
})();
