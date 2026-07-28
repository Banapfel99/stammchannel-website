<?php

declare(strict_types=1);

require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/database.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/clips.php';

requireLogin();

requireClipsSchema($pdo);

$userId = (int) $_SESSION['user_id'];

// The upload widget submits via XMLHttpRequest (see assets/js/clips.js) and
// sends this header. AJAX clients get JSON + precise status codes; a plain
// (JS-disabled) form submit falls back to a redirect with a ?msg= flash.
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;

// Guard so any error path (normal, exception, or fatal shutdown) emits exactly
// one response. Prevents a bare HTTP 500 body that the browser would otherwise
// navigate to (which produced the follow-up GET /clips/upload.php → 405).
$GLOBALS['clipUploadResponded'] = false;

/**
 * Sends the single response for this request and terminates. For AJAX it is a
 * JSON body with an explicit status code; otherwise a redirect back to the
 * StammClips page. Never leaks internal paths or FFmpeg details to the client.
 */
function clipUploadRespond(bool $isAjax, bool $ok, string $message, int $status, ?string $redirect = null): void
{
    if ($GLOBALS['clipUploadResponded'] === true) {
        exit;
    }

    $GLOBALS['clipUploadResponded'] = true;

    if ($isAjax) {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($ok
            ? ['ok' => true, 'redirect' => $redirect ?? '/clips/index.php']
            : ['ok' => false, 'error' => $message]);

        exit;
    }

    if (!headers_sent()) {
        if ($ok) {
            header('Location: ' . ($redirect ?? '/clips/index.php'));
        } else {
            header('Location: /clips/index.php?msg=' . urlencode($message) . '&tab=upload');
        }
    }

    exit;
}

function clipUploadError(bool $isAjax, string $message, int $status = 400): void
{
    clipUploadRespond($isAjax, false, $message, $status);
}

function clipUploadSuccess(bool $isAjax, string $message): void
{
    clipUploadRespond($isAjax, true, $message, 200, '/clips/index.php?msg=' . urlencode($message));
}

// Last-resort safety net: a true fatal (memory/time limit, etc.) still yields a
// clean JSON error instead of a raw 500 page. Non-fatal notices are ignored.
register_shutdown_function(static function () use ($isAjax): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('StammClips-Upload: Fataler Fehler: ' . $error['message']
            . ' in ' . $error['file'] . ':' . $error['line']);

        clipUploadError($isAjax, 'Beim Verarbeiten ist ein unerwarteter Fehler aufgetreten.', 500);
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    clipUploadError($isAjax, 'Nur POST-Anfragen sind erlaubt.', 405);
}

// $tempPath is declared here so the catch block below can always clean it up.
$tempPath = null;

try {
    // A POST that arrives with an empty body despite a non-zero Content-Length
    // means the server-side upload limit (post_max_size / upload_max_filesize)
    // was exceeded before PHP populated $_POST/$_FILES.
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        clipUploadError($isAjax, 'Die Datei überschreitet das Server-Uploadlimit.', 413);
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        clipUploadError($isAjax, 'Ungültige Anfrage.', 403);
    }

    // Simple upload cooldown to avoid accidental/abusive rapid-fire uploads.
    if (countRecentClipUploads($pdo, $userId, 10) > 0) {
        clipUploadError($isAjax, 'Bitte warte kurz, bevor du den nächsten Clip hochlädst.', 429);
    }

    $title = trim($_POST['title'] ?? '');

    if ($title === '' || mb_strlen($title) > 150) {
        clipUploadError($isAjax, 'Bitte gib einen gültigen Titel ein (max. 150 Zeichen).', 422);
    }

    $gameName = trim($_POST['game_name'] ?? '');

    if (mb_strlen($gameName) > 100) {
        $gameName = mb_substr($gameName, 0, 100);
    }

    if (!isset($_FILES['clip']) || !is_array($_FILES['clip'])) {
        clipUploadError($isAjax, 'Bitte wähle eine gültige Videodatei aus.', 422);
    }

    // Translate PHP's upload error codes into friendly, non-technical messages.
    $uploadError = (int) ($_FILES['clip']['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Videodatei ist zu groß.',
            UPLOAD_ERR_PARTIAL => 'Der Upload wurde unterbrochen. Bitte erneut versuchen.',
            UPLOAD_ERR_NO_FILE => 'Bitte wähle eine gültige Videodatei aus.',
            default => 'Die Videodatei konnte nicht empfangen werden.',
        };

        error_log('StammClips-Upload: PHP-Upload-Fehlercode ' . $uploadError);
        clipUploadError($isAjax, $message, 422);
    }

    $clipFile = $_FILES['clip'];
    $maxUploadBytes = getMaxClipUploadBytes($pdo);

    if ((int) $clipFile['size'] > $maxUploadBytes) {
        clipUploadError($isAjax, 'Die Videodatei ist zu groß (max. ' . getMaxClipUploadMb($pdo) . ' MB).', 413);
    }

    if (!is_uploaded_file($clipFile['tmp_name'])) {
        clipUploadError($isAjax, 'Ungültiger Upload.', 400);
    }

    // Never trust the file extension — detect the real container type from the
    // file's magic bytes (finfo reads the actual file signature).
    if (!class_exists('finfo')) {
        error_log('StammClips-Upload: fileinfo-Erweiterung nicht verfügbar.');
        clipUploadError($isAjax, 'Server ist nicht korrekt konfiguriert (fileinfo fehlt).', 500);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string) $finfo->file($clipFile['tmp_name']);

    if (!array_key_exists($detectedMime, CLIP_ALLOWED_INPUT_MIME)) {
        clipUploadError($isAjax, 'Dieses Videoformat wird nicht unterstützt.', 415);
    }

    if (!ensureClipDirectories()) {
        // ensureClipDirectories() already logged the concrete path/permission.
        clipUploadError($isAjax, 'Upload-Verzeichnis ist nicht beschreibbar. Bitte Server-Administrator kontaktieren.', 500);
    }

    $tempExtension = CLIP_ALLOWED_INPUT_MIME[$detectedMime];
    $tempFilename = clipGenerateUuid() . '.' . $tempExtension;
    $tempPath = clipTempDir() . '/' . $tempFilename;

    if (!move_uploaded_file($clipFile['tmp_name'], $tempPath)) {
        error_log('StammClips-Upload: move_uploaded_file fehlgeschlagen. Ziel: ' . $tempPath);
        $tempPath = null;
        clipUploadError($isAjax, 'Videodatei konnte nicht gespeichert werden.', 500);
    }

    $maxDuration = getMaxClipDurationSeconds($pdo);

    // processUploadedClip() probes, validates duration, transcodes and always
    // removes the temp file itself, returning a structured ok/error result.
    $result = processUploadedClip($tempPath, $maxDuration);
    $tempPath = null; // consumed (deleted) by processUploadedClip()

    if (!($result['ok'] ?? false)) {
        clipUploadError($isAjax, $result['error'] ?? 'Der Clip konnte nicht verarbeitet werden.', 422);
    }

    $statement = $pdo->prepare(
        'INSERT INTO clips
            (uploader_id, title, game_name, filename, mime, duration_seconds, width, height, status, rand_seed, processed_at)
         VALUES
            (:uploader_id, :title, :game_name, :filename, :mime, :duration_seconds, :width, :height, \'ready\', :rand_seed, NOW())'
    );

    $statement->execute([
        'uploader_id' => $userId,
        'title' => $title,
        'game_name' => $gameName !== '' ? $gameName : null,
        'filename' => $result['filename'],
        'mime' => 'video/mp4',
        'duration_seconds' => $result['duration'],
        'width' => $result['width'],
        'height' => $result['height'],
        'rand_seed' => clipRandomSeed(),
    ]);

    clipUploadSuccess($isAjax, 'Clip wurde hochgeladen und verarbeitet.');
} catch (Throwable $e) {
    // Any unexpected exception (DB error, FFmpeg glitch, …) is logged in full
    // server-side but reported generically to the client — no internal paths.
    error_log('StammClips-Upload: Ausnahme: ' . $e);

    if ($tempPath !== null && is_file($tempPath)) {
        @unlink($tempPath);
    }

    clipUploadError($isAjax, 'Beim Verarbeiten ist ein unerwarteter Fehler aufgetreten.', 500);
}

