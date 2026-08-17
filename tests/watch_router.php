<?php

declare(strict_types=1);

/**
 * Test router for HttpTransport integration tests.
 * Streams watch frames in 7-byte chunks to exercise partial-line buffering.
 */

ob_implicit_flush(true);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/v3/watch':
        header('Content-Type: application/json');
        for ($i = 1; $i <= 5; $i++) {
            $frame = json_encode([
                'result' => [
                    'header' => ['revision' => $i],
                    'events' => [[
                        'type' => 0,
                        'kv'   => [
                            'key'   => base64_encode("k{$i}"),
                            'value' => base64_encode("v{$i}"),
                        ],
                    ]],
                ],
            ]) . "\n";
            foreach (str_split($frame, 7) as $chunk) {
                echo $chunk;
                usleep(5000);
            }
            usleep(30000);
        }
        sleep(1);
        exit;

    case '/v3/kv/range':
        header('Content-Type: application/json');
        echo json_encode([
            'header' => ['revision' => 1],
            'kvs'    => [[
                'key'              => base64_encode('a'),
                'value'            => base64_encode('b'),
                'create_revision'  => 1,
                'mod_revision'     => 1,
                'version'          => 1,
                'lease'            => 0,
            ]],
            'count'  => 1,
            'more'   => false,
        ]);
        exit;

    case '/v3/maintenance/snapshot':
        header('Content-Type: application/octet-stream');
        echo 'SNAPSHOT-BINARY-DATA-123';
        exit;

    default:
        http_response_code(404);
        echo 'not found';
}
