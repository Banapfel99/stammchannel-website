<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/lies_of_p.php';

// ---------------------------------------------------------------------------
// Tracker token format — the security-critical parts that are pure functions.
// The secret must never be recoverable from the stored hash, and only a
// correctly formatted "lop_<id>.<secret>" token may be parsed.
// ---------------------------------------------------------------------------

test('Token: format + parse round-trip', function () {
    $token = lopFormatTrackerToken(42, 'abcdefghijklmnop');
    $parsed = lopParseTrackerToken($token);

    assertSame(42, $parsed['id']);
    assertSame('abcdefghijklmnop', $parsed['secret']);
});

test('Token: rejects missing prefix', function () {
    assertNull(lopParseTrackerToken('42.abcdefghijklmnop'));
});

test('Token: rejects non-numeric id', function () {
    assertNull(lopParseTrackerToken('lop_x.abcdefghijklmnop'));
});

test('Token: rejects too-short secret', function () {
    assertNull(lopParseTrackerToken('lop_1.short'));
});

test('Token: rejects empty input', function () {
    assertNull(lopParseTrackerToken(''));
});

test('Token: rejects secret with illegal characters', function () {
    assertNull(lopParseTrackerToken('lop_1.abcdefghij klmnop'));
});

test('Token: hash is deterministic and not the plaintext', function () {
    $hash = lopHashTrackerSecret('super-secret-value-123');

    assertSame($hash, lopHashTrackerSecret('super-secret-value-123'));
    assertTrue($hash !== 'super-secret-value-123');
    assertSame(64, strlen($hash)); // sha256 hex
});

test('Token: scope check', function () {
    $token = ['scopes' => 'tracker:read tracker:write'];

    assertTrue(lopTokenHasScope($token, 'tracker:write'));
    assertTrue(lopTokenHasScope($token, 'tracker:read'));
    assertFalse(lopTokenHasScope($token, 'tracker:admin'));
});

test('Token: scope check with a single scope', function () {
    $token = ['scopes' => 'tracker:read'];

    assertTrue(lopTokenHasScope($token, 'tracker:read'));
    assertFalse(lopTokenHasScope($token, 'tracker:write'));
});

// ---------------------------------------------------------------------------
// Event validation — only the whitelisted event types may be ingested.
// ---------------------------------------------------------------------------

test('Event: accepts known types', function () {
    assertTrue(lopIsValidEventType('BOSS_DEFEATED'));
    assertTrue(lopIsValidEventType('DEATH'));
    assertTrue(lopIsValidEventType('GAME_STARTED'));
});

test('Event: rejects unknown types', function () {
    assertFalse(lopIsValidEventType('DROP_TABLE'));
    assertFalse(lopIsValidEventType(''));
    assertFalse(lopIsValidEventType('boss_defeated')); // case-sensitive
});

// ---------------------------------------------------------------------------
// Timeline building — generated from raw events, run-relative offsets.
// ---------------------------------------------------------------------------

test('Timeline: empty input yields empty output', function () {
    assertSame([], lopBuildTimeline([]));
});

test('Timeline: offsets are relative to the first event', function () {
    $base = strtotime('2026-01-01 12:00:00');

    $entries = lopBuildTimeline([
        ['id' => 1, 'event_type' => 'GAME_STARTED', 'label' => null, 'meta' => null,
         'area_name' => null, 'boss_name' => null, 'occurred_at' => date('Y-m-d H:i:s', $base)],
        ['id' => 2, 'event_type' => 'AREA_ENTERED', 'label' => null, 'meta' => null,
         'area_name' => 'Hotel Krat', 'boss_name' => null, 'occurred_at' => date('Y-m-d H:i:s', $base + 37)],
    ]);

    assertSame('00:00', $entries[0]['offset_label']);
    assertSame('00:37', $entries[1]['offset_label']);
    assertSame('Hotel Krat', $entries[1]['title']);
});

test('Timeline: first-try boss defeat is highlighted', function () {
    $base = strtotime('2026-01-01 12:00:00');

    $entries = lopBuildTimeline([
        ['id' => 5, 'event_type' => 'BOSS_DEFEATED', 'label' => null,
         'meta' => json_encode(['first_try' => true, 'attempts' => 1]),
         'area_name' => null, 'boss_name' => 'Scrapped Watchman',
         'occurred_at' => date('Y-m-d H:i:s', $base)],
    ]);

    assertSame('Scrapped Watchman', $entries[0]['title']);
    assertTrue(strpos($entries[0]['detail'], 'First Try') !== false);
});

// ---------------------------------------------------------------------------
// Formatting + spoiler helpers.
// ---------------------------------------------------------------------------

test('Format: playtime humanises seconds', function () {
    assertSame('2h 15m', lopFormatPlaytime(8100));
    assertSame('45m', lopFormatPlaytime(2700));
    assertSame('30s', lopFormatPlaytime(30));
});

test('Format: clock renders mm:ss', function () {
    assertSame('18:42', lopFormatClock(1122));
    assertSame('00:00', lopFormatClock(0));
    assertSame('01:05', lopFormatClock(65));
});

test('Spoiler: level is clamped to 0..3', function () {
    assertSame(0, lopClampSpoilerLevel(-5));
    assertSame(3, lopClampSpoilerLevel(9));
    assertSame(2, lopClampSpoilerLevel(2));
});

test('Spoiler: level labels', function () {
    assertSame('Blind', lopSpoilerLevelLabel(0));
    assertSame('Full Guide', lopSpoilerLevelLabel(3));
});

test('Avatar: initials from username', function () {
    assertSame('NI', lopAvatarInitials('Niklas'));
    assertSame('JD', lopAvatarInitials('John Doe'));
    assertSame('?', lopAvatarInitials('   '));
});
