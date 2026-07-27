<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/watch.php';

// ---------------------------------------------------------------------------
// watchExtractYoutubeId — the security-critical input validation. Only bare
// 11-char IDs must ever be derived; arbitrary URLs/embeds must be rejected.
// ---------------------------------------------------------------------------

test('YouTube: standard watch URL', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
});

test('YouTube: watch URL with extra params', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PLxyz'));
});

test('YouTube: short youtu.be URL', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('https://youtu.be/dQw4w9WgXcQ'));
});

test('YouTube: embed URL', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('https://www.youtube.com/embed/dQw4w9WgXcQ'));
});

test('YouTube: shorts URL', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
});

test('YouTube: bare 11-char id', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('dQw4w9WgXcQ'));
});

test('YouTube: id with dashes and underscores', function () {
    assertSame('a-b_c1234XY', watchExtractYoutubeId('https://youtu.be/a-b_c1234XY'));
});

test('YouTube: rejects empty input', function () {
    assertNull(watchExtractYoutubeId(''));
});

test('YouTube: rejects non-youtube URL', function () {
    assertNull(watchExtractYoutubeId('https://example.com/watch?v=dQw4w9WgXcQ_TOO_LONG'));
});

test('YouTube: rejects youtube URL with over-long v param', function () {
    assertNull(watchExtractYoutubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQEXTRA'));
});

test('YouTube: accepts youtube-nocookie embed', function () {
    assertSame('dQw4w9WgXcQ', watchExtractYoutubeId('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'));
});

test('YouTube: rejects too-short id', function () {
    assertNull(watchExtractYoutubeId('shortid'));
});

test('YouTube: rejects arbitrary embed html', function () {
    assertNull(watchExtractYoutubeId('<iframe src="https://evil.example/x"></iframe>'));
});

test('YouTube: rejects javascript scheme', function () {
    assertNull(watchExtractYoutubeId('javascript:alert(1)'));
});

// ---------------------------------------------------------------------------
// watchComputeLivePosition — playback sync extrapolation. While playing, the
// live head advances with wall-clock time; while paused it stays put.
// ---------------------------------------------------------------------------

test('Sync: paused position does not advance', function () {
    $room = [
        'playback_state' => 'paused',
        'playback_position' => 30.0,
        'position_updated_at' => date('Y-m-d H:i:s', time() - 10),
    ];
    assertSame(30.0, watchComputeLivePosition($room));
});

test('Sync: playing position advances by elapsed time', function () {
    $room = [
        'playback_state' => 'playing',
        'playback_position' => 30.0,
        'position_updated_at' => date('Y-m-d H:i:s', time() - 5),
    ];
    $live = watchComputeLivePosition($room);
    // Allow a 1s tolerance for clock/second-rounding at the boundary.
    assertTrue($live >= 34.0 && $live <= 36.0, 'Expected ~35, got ' . $live);
});

test('Sync: playing from zero advances', function () {
    $room = [
        'playback_state' => 'playing',
        'playback_position' => 0.0,
        'position_updated_at' => date('Y-m-d H:i:s', time() - 3),
    ];
    assertTrue(watchComputeLivePosition($room) >= 2.0, 'Expected >= 2 seconds elapsed.');
});
