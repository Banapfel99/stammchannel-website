<?php

declare(strict_types=1);

const CLIP_ALLOWED_INPUT_MIME = [
    'video/mp4' => 'mp4',
    'video/quicktime' => 'mov',
    'video/webm' => 'webm',
    'video/x-matroska' => 'mkv',
    'video/x-msvideo' => 'avi',
];

const CLIP_DEFAULT_MAX_UPLOAD_MB = 150;
const CLIP_DEFAULT_MAX_DURATION_SECONDS = 30;
const CLIP_REACTION_TYPES = ['funny', 'nice', 'rip'];

/** @var array<string, mixed>|null */
function clipsAppConfig(): array
{
    static $config = null;

    if ($config === null) {
        $configFile = __DIR__ . '/../config/config.php';
        $config = is_file($configFile) ? require $configFile : [];
    }

    return $config;
}

function clipStorageRoot(): string
{
    $config = clipsAppConfig();

    return rtrim((string) ($config['storage']['clips_path'] ?? __DIR__ . '/../storage/clips'), '/');
}

function clipProcessedDir(): string
{
    return clipStorageRoot() . '/processed';
}

function clipTempDir(): string
{
    return clipStorageRoot() . '/temp';
}

function clipFfmpegBinary(): string
{
    return clipResolveBinary(
        (string) (clipsAppConfig()['storage']['ffmpeg_binary'] ?? 'ffmpeg'),
        ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg']
    );
}

function clipFfprobeBinary(): string
{
    return clipResolveBinary(
        (string) (clipsAppConfig()['storage']['ffprobe_binary'] ?? 'ffprobe'),
        ['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe']
    );
}

/**
 * Resolves a configured binary name to a usable path. An absolute/relative path
 * (containing a directory separator) is trusted as-is. A bare command name is
 * matched against a list of well-known install locations, so the tool is found
 * even when php-fpm runs with a minimal PATH that lacks /usr/bin. Falls back to
 * the bare name (relying on PATH) when no candidate exists.
 *
 * @param string[] $fallbacks
 */
function clipResolveBinary(string $configured, array $fallbacks): string
{
    if ($configured !== '' && strpbrk($configured, '/\\') !== false) {
        return $configured;
    }

    foreach ($fallbacks as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return $configured;
}

function ensureClipDirectories(): bool
{
    foreach ([clipStorageRoot(), clipProcessedDir(), clipTempDir()] as $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                error_log('StammClips: Verzeichnis konnte nicht erstellt werden: ' . $dir);

                return false;
            }
        }

        if (!is_writable($dir)) {
            error_log('StammClips: Verzeichnis ist nicht beschreibbar: ' . $dir);

            return false;
        }
    }

    return true;
}

function getMaxClipUploadMb(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'max_clip_upload_mb', (string) CLIP_DEFAULT_MAX_UPLOAD_MB));
}

function getMaxClipUploadBytes(PDO $pdo): int
{
    return getMaxClipUploadMb($pdo) * 1024 * 1024;
}

function getMaxClipDurationSeconds(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'max_clip_duration_seconds', (string) CLIP_DEFAULT_MAX_DURATION_SECONDS));
}

function clipGenerateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    $hex = bin2hex($data);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function clipRandomSeed(): float
{
    return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
}

/**
 * Runs a whitelisted binary with an argument array via proc_open (no shell
 * involved), so user-controlled values can never break out into shell syntax.
 *
 * @param string[] $args
 */
function clipRunProcess(array $args, ?string &$stdout = null, ?string &$stderr = null): bool
{
    $stdout = '';
    $stderr = '';

    if (!function_exists('proc_open')) {
        $stderr = 'proc_open ist auf diesem Server deaktiviert.';
        error_log('StammClips: ' . $stderr);

        return false;
    }

    // stdin is read from /dev/null so the child never blocks waiting for input.
    // (Windows has no /dev/null, but this deployment is Linux/nginx.)
    $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    $descriptors = [
        0 => ['file', $devNull, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($args, $descriptors, $pipes, null, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
        $stderr = 'Prozess konnte nicht gestartet werden: ' . ($args[0] ?? '');
        error_log('StammClips: ' . $stderr);

        return false;
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return $exitCode === 0;
}

/**
 * Probes a video file with ffprobe and returns duration/resolution, or null
 * if the file could not be read as a video. Failures are logged (including the
 * ffprobe stderr) so upload problems can be diagnosed server-side.
 *
 * @return array{duration: float, width: int, height: int}|null
 */
function clipProbeVideo(string $path): ?array
{
    $args = [
        clipFfprobeBinary(),
        '-v', 'error',
        '-print_format', 'json',
        '-show_streams',
        '-show_format',
        $path,
    ];

    if (!clipRunProcess($args, $stdout, $stderr) || $stdout === '') {
        error_log('StammClips: ffprobe fehlgeschlagen für ' . $path . ' — ' . trim((string) $stderr));

        return null;
    }

    $data = json_decode($stdout, true);

    if (!is_array($data) || empty($data['streams'])) {
        error_log('StammClips: ffprobe lieferte keine verwertbaren Streams für ' . $path);

        return null;
    }

    // Pick the first real video stream. Attached cover art (attached_pic) is a
    // video-typed stream too but must be ignored, otherwise clips with embedded
    // thumbnails would be misdetected or rejected as "keine gültige Videospur".
    $videoStream = null;

    foreach ($data['streams'] as $stream) {
        if (($stream['codec_type'] ?? '') !== 'video') {
            continue;
        }

        if (!empty($stream['disposition']['attached_pic'])) {
            continue;
        }

        if (!empty($stream['width']) && !empty($stream['height'])) {
            $videoStream = $stream;
            break;
        }
    }

    if ($videoStream === null) {
        error_log('StammClips: Keine gültige Videospur in ' . $path);

        return null;
    }

    // Duration usually lives in format; fall back to the video stream's own
    // duration for containers (e.g. some MKV/AVI) that omit the format value.
    $duration = (float) ($data['format']['duration'] ?? 0);

    if ($duration <= 0) {
        $duration = (float) ($videoStream['duration'] ?? 0);
    }

    if ($duration <= 0) {
        error_log('StammClips: Konnte Videodauer nicht ermitteln für ' . $path);

        return null;
    }

    return [
        'duration' => $duration,
        'width' => (int) $videoStream['width'],
        'height' => (int) $videoStream['height'],
    ];
}

/**
 * Transcodes a source video to a web-optimized H.264/AAC MP4 capped at 1080p.
 * A missing audio track is fine — ffmpeg simply produces a video-only MP4.
 * Failures are logged together with the ffmpeg stderr for diagnosis.
 */
function clipTranscodeVideo(string $input, string $output, int $maxDurationSeconds): bool
{
    $args = [
        clipFfmpegBinary(),
        '-y',
        '-i', $input,
        '-t', (string) $maxDurationSeconds,
        '-vf', 'scale=-2:\'min(1080,ih)\'',
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '23',
        '-c:a', 'aac',
        '-b:a', '128k',
        '-movflags', '+faststart',
        $output,
    ];

    $ok = clipRunProcess($args, $stdout, $stderr);

    if (!$ok || !is_file($output) || filesize($output) === 0) {
        error_log('StammClips: ffmpeg-Transkodierung fehlgeschlagen für ' . $input . ' — ' . trim((string) $stderr));

        return false;
    }

    return true;
}

/**
 * Full processing pipeline for a freshly uploaded (temp) clip file: probe,
 * validate duration, transcode to the processed directory as a UUID-named
 * MP4, then remove the original temp file. Returns the processing result.
 *
 * @return array{ok: bool, error?: string, filename?: string, duration?: float, width?: int, height?: int}
 */
function processUploadedClip(string $tempPath, int $maxDurationSeconds): array
{
    $info = clipProbeVideo($tempPath);

    if ($info === null) {
        @unlink($tempPath);

        return ['ok' => false, 'error' => 'Die Datei enthält keine gültige Videospur.'];
    }

    if ($info['duration'] > $maxDurationSeconds + 0.75) {
        @unlink($tempPath);

        return ['ok' => false, 'error' => 'Der Clip ist zu lang (max. ' . $maxDurationSeconds . ' Sekunden).'];
    }

    $uuid = clipGenerateUuid();
    $outputFilename = $uuid . '.mp4';
    $outputPath = clipProcessedDir() . '/' . $outputFilename;

    if (!clipTranscodeVideo($tempPath, $outputPath, $maxDurationSeconds)) {
        @unlink($tempPath);
        @unlink($outputPath);

        return ['ok' => false, 'error' => 'Der Clip konnte nicht verarbeitet werden.'];
    }

    @unlink($tempPath);

    $outputInfo = clipProbeVideo($outputPath) ?? $info;

    return [
        'ok' => true,
        'filename' => $outputFilename,
        'duration' => round($outputInfo['duration'], 2),
        'width' => $outputInfo['width'],
        'height' => $outputInfo['height'],
    ];
}

/**
 * Scalable random clip selection: seeks to a random anchor value on the
 * indexed rand_seed column instead of sorting the whole table with
 * `ORDER BY RAND()`. Optionally excludes clip ids the current session has
 * already seen recently, so the same clips don't repeat back to back.
 *
 * @param int[] $excludeIds
 */
function pickRandomClip(PDO $pdo, array $excludeIds = []): ?array
{
    $excludeIds = array_values(array_unique(array_map('intval', $excludeIds)));

    $totalReady = (int) $pdo->query("SELECT COUNT(*) FROM clips WHERE status = 'ready'")->fetchColumn();

    if ($totalReady === 0) {
        return null;
    }

    // If excluding recently seen clips would remove everything, ignore the
    // exclusion instead of returning nothing.
    if (count($excludeIds) >= $totalReady) {
        $excludeIds = [];
    }

    $seed = clipRandomSeed();
    $exclude = '';
    $params = ['seed' => $seed];

    if ($excludeIds !== []) {
        $placeholders = [];

        foreach ($excludeIds as $index => $id) {
            $key = 'ex' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $exclude = ' AND id NOT IN (' . implode(',', $placeholders) . ')';
    }

    foreach (['>=', '<'] as $operator) {
        $statement = $pdo->prepare(
            "SELECT c.id, c.uploader_id, c.title, c.game_name, c.filename, c.mime,
                    c.duration_seconds, c.width, c.height, c.created_at,
                    u.username AS uploader_username
             FROM clips c
             JOIN users u ON u.id = c.uploader_id
             WHERE c.status = 'ready' AND c.rand_seed {$operator} :seed{$exclude}
             ORDER BY c.rand_seed " . ($operator === '>=' ? 'ASC' : 'DESC') . "
             LIMIT 1"
        );
        $statement->execute($params);
        $clip = $statement->fetch();

        if ($clip !== false) {
            return $clip;
        }
    }

    return null;
}

function recordClipView(PDO $pdo, int $clipId, ?int $userId): void
{
    $statement = $pdo->prepare('INSERT INTO clip_views (clip_id, user_id) VALUES (:clip_id, :user_id)');
    $statement->execute(['clip_id' => $clipId, 'user_id' => $userId]);
}

function getClipViewCount(PDO $pdo, int $clipId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM clip_views WHERE clip_id = :clip_id');
    $statement->execute(['clip_id' => $clipId]);

    return (int) $statement->fetchColumn();
}

/**
 * Toggles a reaction for a user on a clip (adds it if missing, removes it if
 * present). Returns the new active state (true = now active).
 */
function toggleClipReaction(PDO $pdo, int $clipId, int $userId, string $reactionType): bool
{
    if (!in_array($reactionType, CLIP_REACTION_TYPES, true)) {
        throw new InvalidArgumentException('Unbekannter Reaktionstyp.');
    }

    $check = $pdo->prepare(
        'SELECT 1 FROM clip_reactions WHERE clip_id = :clip_id AND user_id = :user_id AND reaction_type = :type LIMIT 1'
    );
    $check->execute(['clip_id' => $clipId, 'user_id' => $userId, 'type' => $reactionType]);

    if ($check->fetch() !== false) {
        $delete = $pdo->prepare(
            'DELETE FROM clip_reactions WHERE clip_id = :clip_id AND user_id = :user_id AND reaction_type = :type'
        );
        $delete->execute(['clip_id' => $clipId, 'user_id' => $userId, 'type' => $reactionType]);

        return false;
    }

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO clip_reactions (clip_id, user_id, reaction_type) VALUES (:clip_id, :user_id, :type)'
    );
    $insert->execute(['clip_id' => $clipId, 'user_id' => $userId, 'type' => $reactionType]);

    return true;
}

/**
 * @return array<string, int>
 */
function getClipReactionCounts(PDO $pdo, int $clipId): array
{
    $counts = array_fill_keys(CLIP_REACTION_TYPES, 0);

    $statement = $pdo->prepare(
        'SELECT reaction_type, COUNT(*) AS total FROM clip_reactions WHERE clip_id = :clip_id GROUP BY reaction_type'
    );
    $statement->execute(['clip_id' => $clipId]);

    foreach ($statement->fetchAll() as $row) {
        $counts[$row['reaction_type']] = (int) $row['total'];
    }

    return $counts;
}

/**
 * @return string[] reaction types the user has active on this clip
 */
function getUserClipReactions(PDO $pdo, int $clipId, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT reaction_type FROM clip_reactions WHERE clip_id = :clip_id AND user_id = :user_id'
    );
    $statement->execute(['clip_id' => $clipId, 'user_id' => $userId]);

    return array_column($statement->fetchAll(), 'reaction_type');
}

function countRecentClipUploads(PDO $pdo, int $userId, int $seconds): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM clips WHERE uploader_id = :user_id AND created_at >= (NOW() - INTERVAL :seconds SECOND)'
    );
    $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue('seconds', $seconds, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/**
 * @return string[] distinct game names already used, for a datalist suggestion
 */
function getKnownClipGameNames(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT DISTINCT game_name FROM clips WHERE game_name IS NOT NULL AND game_name <> '' ORDER BY game_name ASC LIMIT 50"
    )->fetchAll();

    return array_column($rows, 'game_name');
}

function clipRelativeTime(string $datetime): string
{
    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return $datetime;
    }

    $diff = max(0, time() - $timestamp);

    if ($diff < 60) {
        return 'gerade eben';
    }

    if ($diff < 3600) {
        $minutes = (int) floor($diff / 60);

        return 'vor ' . $minutes . ' Minute' . ($minutes === 1 ? '' : 'n');
    }

    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);

        return 'vor ' . $hours . ' Stunde' . ($hours === 1 ? '' : 'n');
    }

    $days = (int) floor($diff / 86400);

    if ($days < 30) {
        return 'vor ' . $days . ' Tag' . ($days === 1 ? '' : 'en');
    }

    $months = (int) floor($days / 30);

    if ($months < 12) {
        return 'vor ' . $months . ' Monat' . ($months === 1 ? '' : 'en');
    }

    $years = (int) floor($months / 12);

    return 'vor ' . $years . ' Jahr' . ($years === 1 ? '' : 'en');
}

function clipsSchemaReady(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT 1 FROM clips LIMIT 1');
        $ready = true;
    } catch (PDOException $e) {
        $ready = false;
    }

    return $ready;
}

function requireClipsSchema(PDO $pdo): void
{
    if (clipsSchemaReady($pdo)) {
        return;
    }

    require_once __DIR__ . '/assets.php';

    http_response_code(503);

    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
        . '<title>StammClips nicht verfügbar</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(asset('/assets/css/style.css')) . '"></head><body>'
        . '<main class="content"><h1>StammClips ist noch nicht eingerichtet</h1>'
        . '<p>Die Datenbanktabellen für StammClips fehlen. Bitte führe '
        . '<code>database/clips_schema.sql</code> einmalig gegen die Datenbank aus, '
        . 'dann lade diese Seite erneut.</p>'
        . '<p><a href="/dashboard.php">Zurück zum Dashboard</a></p>'
        . '</main></body></html>';

    exit;
}
