-- Watch Together schema.
-- Assumes an existing `users` table with an `id` primary key (INT UNSIGNED / INT)
-- and the `app_settings` table from database/music_schema.sql.
-- Run this once against the stammchannel_site database.
--
-- The server is the authoritative source for room state. Only YouTube video
-- IDs (never arbitrary embed/HTML) are stored. Real-time delivery happens via
-- Server-Sent Events (watch/stream.php) which poll the version counters below.

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('watch_chat_message_max_length', '500');

CREATE TABLE IF NOT EXISTS watch_rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    host_id INT UNSIGNED NOT NULL,
    -- Currently playing queue item (NULL = nothing selected yet).
    current_item_id INT UNSIGNED DEFAULT NULL,
    playback_state ENUM('playing', 'paused') NOT NULL DEFAULT 'paused',
    -- Playback head position in seconds at the moment position_updated_at was set.
    -- Clients extrapolate the live position while state = 'playing'.
    playback_position DECIMAL(10,3) NOT NULL DEFAULT 0,
    position_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Bumped on every playback/current-video/host/participant change so the SSE
    -- loop can detect state changes with a single cheap read.
    state_version INT UNSIGNED NOT NULL DEFAULT 0,
    -- Bumped whenever the queue changes.
    queue_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_watch_room_host FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_watch_rooms_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watch_queue_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    youtube_id VARCHAR(20) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    added_by INT UNSIGNED DEFAULT NULL,
    -- Monotonic ordering value; new items are appended with a higher sort_order.
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_watch_queue_room FOREIGN KEY (room_id) REFERENCES watch_rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_watch_queue_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_watch_queue_room_order (room_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- current_item_id references a queue item. Declared after the queue table exists.
ALTER TABLE watch_rooms
    ADD CONSTRAINT fk_watch_room_current_item
    FOREIGN KEY (current_item_id) REFERENCES watch_queue_items(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS watch_participants (
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, user_id),
    CONSTRAINT fk_watch_part_room FOREIGN KEY (room_id) REFERENCES watch_rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_watch_part_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_watch_part_seen (room_id, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS watch_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    body VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_watch_msg_room FOREIGN KEY (room_id) REFERENCES watch_rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_watch_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_watch_msg_room_id (room_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
