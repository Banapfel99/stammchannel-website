-- StammClips schema.
-- Assumes an existing `users` table with an `id` primary key (INT UNSIGNED / INT)
-- and the `app_settings` table from database/music_schema.sql.
-- Run this once against the stammchannel_site database.

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('max_clip_upload_mb', '150');

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('max_clip_duration_seconds', '30');

CREATE TABLE IF NOT EXISTS clips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uploader_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    game_name VARCHAR(100) DEFAULT NULL,
    filename VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL DEFAULT 'video/mp4',
    duration_seconds DECIMAL(5,2) NOT NULL DEFAULT 0,
    width SMALLINT UNSIGNED DEFAULT NULL,
    height SMALLINT UNSIGNED DEFAULT NULL,
    status ENUM('processing', 'ready', 'failed') NOT NULL DEFAULT 'processing',
    -- Random anchor value in [0, 1) assigned once at insert time. Combined with an
    -- index this allows a scalable "random clip" lookup (seek to a random value,
    -- take the next row) instead of `ORDER BY RAND()` over the whole table.
    rand_seed DOUBLE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    CONSTRAINT fk_clips_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_clips_rand_seed (status, rand_seed),
    INDEX idx_clips_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clip_reactions (
    clip_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reaction_type ENUM('funny', 'nice', 'rip') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (clip_id, user_id, reaction_type),
    CONSTRAINT fk_reaction_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE,
    CONSTRAINT fk_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clip_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clip_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_view_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE,
    CONSTRAINT fk_view_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_views_clip (clip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
