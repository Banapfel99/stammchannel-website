<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'database' => 'stammchannel_site',
        'username' => 'stammchannel_site',
        'password' => 'CHANGE_ME',
    ],

    // Speicherort und Werkzeuge fuer die Videoverarbeitung von StammClips.
    // clips_path liegt bewusst ausserhalb von /uploads bzw. dem Web-Root und
    // muss serverseitig (nginx) ebenfalls vor Direktzugriff geschuetzt werden.
    'storage' => [
        'clips_path' => __DIR__ . '/../storage/clips',
        'ffmpeg_binary' => 'ffmpeg',
        'ffprobe_binary' => 'ffprobe',
    ],
];