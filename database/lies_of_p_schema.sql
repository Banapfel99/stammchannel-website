-- Lies of P – StammRun schema (generic game-tracking foundation).
--
-- Assumes an existing `users` table with an `id` primary key (INT UNSIGNED)
-- and the `app_settings` table from database/music_schema.sql.
-- Run this once against the stammchannel_site database:
--
--   mysql stammchannel_site < database/lies_of_p_schema.sql
--
-- Design notes
-- ------------
-- * The tables are intentionally game-agnostic (a `games` row identifies the
--   title) so the same schema can later host Dark Souls III, Elden Ring, etc.
--   Lies-of-P-specific rows live only in the *seed data* at the bottom.
-- * The website never exchanges savegames. A separate local "StammTracker"
--   client will POST extracted progress/stat data to the REST API
--   (api/tracker.php) authenticated with a personal, hashed tracker token.
-- * Only real, synced data is ever displayed. This migration seeds the *catalog*
--   (areas / bosses – factual game data) but deliberately seeds NO player
--   numbers, so the dashboard shows honest empty states until a client syncs.

-- --------------------------------------------------------------------------
-- Settings
-- --------------------------------------------------------------------------
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('lop_default_spoiler_level', '0'),
    ('tracker_rate_limit_per_minute', '120');

-- --------------------------------------------------------------------------
-- Generic game catalog
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_games_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_areas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    has_missables TINYINT(1) NOT NULL DEFAULT 0,
    -- Progressive disclosure text keyed to the user's spoiler level (0..3).
    spoiler_warning VARCHAR(255) DEFAULT NULL, -- level 0 (blind): vague warning
    spoiler_hint VARCHAR(255) DEFAULT NULL,    -- level 1: gentle hint
    spoiler_guided VARCHAR(500) DEFAULT NULL,  -- level 2: concrete NPCs/places
    spoiler_full TEXT DEFAULT NULL,            -- level 3: exact steps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_area_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    INDEX idx_area_game_order (game_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_bosses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    area_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(120) NOT NULL,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_boss_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_boss_area FOREIGN KEY (area_id) REFERENCES game_areas(id) ON DELETE SET NULL,
    INDEX idx_boss_game_order (game_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prepared catalog tables for the later Achievement / Missable Assistant.
CREATE TABLE IF NOT EXISTS game_quests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    area_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(160) NOT NULL,
    is_missable TINYINT(1) NOT NULL DEFAULT 0,
    spoiler_hint VARCHAR(255) DEFAULT NULL,
    spoiler_guided VARCHAR(500) DEFAULT NULL,
    spoiler_full TEXT DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_quest_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_quest_area FOREIGN KEY (area_id) REFERENCES game_areas(id) ON DELETE SET NULL,
    INDEX idx_quest_game (game_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_collectibles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    area_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(160) NOT NULL,
    is_missable TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_collectible_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_collectible_area FOREIGN KEY (area_id) REFERENCES game_areas(id) ON DELETE SET NULL,
    INDEX idx_collectible_game (game_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS achievements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT DEFAULT NULL,
    is_missable TINYINT(1) NOT NULL DEFAULT 0,
    point_of_no_return_area_id INT UNSIGNED DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_achievement_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_achievement_ponr FOREIGN KEY (point_of_no_return_area_id) REFERENCES game_areas(id) ON DELETE SET NULL,
    UNIQUE KEY uq_achievement_code (game_id, code),
    INDEX idx_achievement_game (game_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS achievement_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    achievement_id INT UNSIGNED NOT NULL,
    requirement_type VARCHAR(40) NOT NULL, -- e.g. BOSS_DEFEATED, ITEM_COLLECTED, QUEST_COMPLETED
    target_ref VARCHAR(120) DEFAULT NULL,  -- free-form reference resolved by the tracker logic
    description VARCHAR(255) DEFAULT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_req_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
    INDEX idx_req_achievement (achievement_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
-- Shared run + per-player progress
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    game_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    status ENUM('active', 'completed', 'archived') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_run_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_run_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_run_game_status (game_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_run_participants (
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (run_id, user_id),
    CONSTRAINT fk_participant_run FOREIGN KEY (run_id) REFERENCES game_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS player_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    playtime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    deaths INT UNSIGNED NOT NULL DEFAULT 0,
    current_area_id INT UNSIGNED DEFAULT NULL,
    current_area_name VARCHAR(120) DEFAULT NULL,
    last_progress_label VARCHAR(160) DEFAULT NULL,
    last_synced_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_progress_run_user (run_id, user_id),
    CONSTRAINT fk_progress_run FOREIGN KEY (run_id) REFERENCES game_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_area FOREIGN KEY (current_area_id) REFERENCES game_areas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boss_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    boss_id INT UNSIGNED NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    deaths INT UNSIGNED NOT NULL DEFAULT 0,
    time_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('undefeated', 'defeated') NOT NULL DEFAULT 'undefeated',
    first_try TINYINT(1) NOT NULL DEFAULT 0,
    defeated_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_boss_run_user_boss (run_id, user_id, boss_id),
    CONSTRAINT fk_bossprog_run FOREIGN KEY (run_id) REFERENCES game_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_bossprog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bossprog_boss FOREIGN KEY (boss_id) REFERENCES game_bosses(id) ON DELETE CASCADE,
    INDEX idx_bossprog_run_boss (run_id, boss_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Timeline is generated from these raw events, never stored as rendered text.
CREATE TABLE IF NOT EXISTS game_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    area_id INT UNSIGNED DEFAULT NULL,
    boss_id INT UNSIGNED DEFAULT NULL,
    label VARCHAR(200) DEFAULT NULL,
    meta JSON DEFAULT NULL,
    -- Optional client-supplied de-duplication key (idempotent event ingestion).
    client_event_id VARCHAR(80) DEFAULT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_run FOREIGN KEY (run_id) REFERENCES game_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_area FOREIGN KEY (area_id) REFERENCES game_areas(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_boss FOREIGN KEY (boss_id) REFERENCES game_bosses(id) ON DELETE SET NULL,
    UNIQUE KEY uq_event_client (run_id, user_id, client_event_id),
    INDEX idx_event_timeline (run_id, user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user, per-game spoiler preference (0 = blind … 3 = full guide).
CREATE TABLE IF NOT EXISTS game_user_settings (
    user_id INT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NOT NULL,
    spoiler_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, game_id),
    CONSTRAINT fk_usersettings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_usersettings_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
-- Tracker tokens (personal API credentials for the local StammTracker client)
-- --------------------------------------------------------------------------
-- The plaintext token is shown to the user exactly once and never stored.
-- Only a SHA-256 hash of the secret part is persisted. The token id is carried
-- in the token string itself so verification is an O(1) lookup + hash_equals.
CREATE TABLE IF NOT EXISTS tracker_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL DEFAULT 'StammTracker',
    token_hash CHAR(64) NOT NULL,
    scopes VARCHAR(255) NOT NULL DEFAULT 'tracker:write',
    last_used_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed-window rate limiting for the tracker API (one row per token).
CREATE TABLE IF NOT EXISTS tracker_rate_limits (
    token_id INT UNSIGNED NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_ratelimit_token FOREIGN KEY (token_id) REFERENCES tracker_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
-- Seed data — factual Lies of P catalog (areas & bosses). No player numbers.
-- ==========================================================================
INSERT IGNORE INTO games (slug, name) VALUES ('lies-of-p', 'Lies of P');

SET @lop := (SELECT id FROM games WHERE slug = 'lies-of-p' LIMIT 1);

INSERT IGNORE INTO game_areas (game_id, name, sort_order, has_missables, spoiler_warning) VALUES
    (@lop, 'Hotel Krat', 10, 1, '⚠ In diesem Gebiet befindet sich etwas Verpassbares.'),
    (@lop, 'Krat Central Station', 20, 0, NULL),
    (@lop, 'Elysion Boulevard', 30, 1, '⚠ In diesem Gebiet befindet sich etwas Verpassbares.'),
    (@lop, 'Workshop Union Culvert', 40, 0, NULL),
    (@lop, 'St. Frangelico Cathedral', 50, 1, '⚠ In diesem Gebiet befindet sich etwas Verpassbares.'),
    (@lop, 'Malum District', 60, 1, '⚠ In diesem Gebiet befindet sich etwas Verpassbares.'),
    (@lop, 'Rosa Isabelle Street', 70, 1, '⚠ In diesem Gebiet befindet sich etwas Verpassbares.'),
    (@lop, 'Grand Exhibition', 80, 0, NULL),
    (@lop, 'Barren Swamp', 90, 0, NULL),
    (@lop, 'Ascension Bridge', 100, 0, NULL),
    (@lop, 'Arche Abbey', 110, 1, '⚠ In diesem Gebiet befindet sich etwas Verpassbares.');

INSERT IGNORE INTO game_bosses (game_id, area_id, name, is_optional, sort_order) VALUES
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Krat Central Station' LIMIT 1), 'Parade Master', 0, 10),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Elysion Boulevard' LIMIT 1), 'Scrapped Watchman', 0, 20),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Workshop Union Culvert' LIMIT 1), 'King''s Flame, Fuoco', 0, 30),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'St. Frangelico Cathedral' LIMIT 1), 'Fallen Archbishop Andreus', 0, 40),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Malum District' LIMIT 1), 'Eldest of the Black Rabbit Brotherhood', 0, 50),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Rosa Isabelle Street' LIMIT 1), 'The King of Puppets', 0, 60),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Grand Exhibition' LIMIT 1), 'Champion Victor', 0, 70),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Grand Exhibition' LIMIT 1), 'Green Monster of the Swamp', 0, 80),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Barren Swamp' LIMIT 1), 'Corrupted Parade Master', 1, 90),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Ascension Bridge' LIMIT 1), 'Black Rabbit Brotherhood', 0, 100),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Arche Abbey' LIMIT 1), 'Laxasia the Complete', 0, 110),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Arche Abbey' LIMIT 1), 'Simon Manus, Awakened God', 0, 120),
    (@lop, (SELECT id FROM game_areas WHERE game_id = @lop AND name = 'Arche Abbey' LIMIT 1), 'Nameless Puppet', 0, 130);
