<?php

// Exercise the real response emitter over HTTP, including headers and HEAD.
function checkAvailability($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
checkAvailability(is_resource($listener), 'Cannot reserve a local HTTP test port: ' . $errorMessage);
$address = stream_socket_get_name($listener, false);
fclose($listener);
$command = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' '
    . escapeshellarg(__DIR__ . '/fixtures/product-request.php');
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
    dirname(__DIR__), null, ['bypass_shell' => true]);
checkAvailability(is_resource($process), 'Cannot start local PHP HTTP server.');
fclose($pipes[0]);

try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) {
        stream_set_blocking($pipes[2], false);
        throw new RuntimeException('Local PHP HTTP server did not start: ' . stream_get_contents($pipes[2]));
    }
    $cases = [
        [['id_product' => 7], 'GET', 503],
        [['id_product' => 7], 'HEAD', 503],
        [['id_product' => 11, 'shop' => 2], 'GET', 503],
        [['id_product' => 7, 'escape' => 1], 'GET', 503],
        [['id_product' => 8], 'GET', 200], // Reactivated.
        [['id_product' => 9], 'GET', 200], // Manually disabled.
        [['id_product' => 10], 'GET', 200], // Deleted, stale tracking.
        [['id_product' => 11], 'GET', 200], // Tracked only in another shop.
        [['id_product' => 12], 'GET', 200], // Customer group has no access.
        [['id_product' => 7, 'preview' => 1], 'GET', 200],
        [['id_product' => 7, 'ajax' => 1], 'GET', 200],
        [['id_product' => 7, 'other' => 1], 'GET', 200],
        [['id_product' => 7], 'POST', 200],
        [['id_product' => 999], 'GET', 200],
        [['id_product' => [7]], 'GET', 200],
        [['id_product' => '7garbage'], 'GET', 200],
        [['id_product' => -1], 'GET', 200],
        [[], 'GET', 200],
    ];
    foreach ($cases as $case) {
        $context = stream_context_create(['http' => [
            'method' => $case[1], 'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $body = file_get_contents('http://' . $address . '/?' . http_build_query($case[0]), false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers() : $http_response_header;
        $headers = implode("\n", $responseHeaders);
        checkAvailability($body !== false && strpos($responseHeaders[0], ' ' . $case[2] . ' ') !== false,
            'Unexpected HTTP response for ' . json_encode($case) . ': ' . $headers . ' / ' . $body);
        if ($case[2] === 503) {
            checkAvailability(stripos($headers, 'Retry-After: 3600') !== false, 'Retry-After is required.');
            checkAvailability(stripos($headers, 'no-store') !== false, 'Temporary error must not be cached.');
            checkAvailability(stripos($headers, 'Content-Type: text/html; charset=utf-8') !== false, 'HTML response charset missing.');
            checkAvailability(stripos($headers . $body, 'noindex') === false, 'Temporary error must not send noindex.');
            checkAvailability(stripos($headers, 'Location:') === false, 'Product URL must not redirect.');
            checkAvailability(stripos($headers, 'X-Native-Controller:') === false, '503 must stop before native 404.');
            if ($case[1] === 'HEAD') {
                checkAvailability($body === '', 'HEAD must not return a body.');
            } else {
                checkAvailability(strpos($body, 'temporarily unavailable') !== false, 'Unavailable message missing.');
                $shop = isset($case[0]['shop']) ? $case[0]['shop'] : 1;
                checkAvailability(strpos($body, 'https://shop' . $shop . '.test/?lang=1&amp;home=1') !== false,
                    'Return URL must respect the shop and escape HTML.');
                checkAvailability(strpos($body, '<script>') === false, 'Template must escape attributes.');
            }
        } else {
            checkAvailability(stripos($headers, 'X-Native-Controller: reached') !== false && $body === 'native controller',
                'Unrelated requests must continue through PrestaShop unchanged.');
            checkAvailability(stripos($headers, 'Retry-After:') === false, 'Unrelated requests must not acquire temporary headers.');
        }
    }
    echo 'Product availability tests passed (' . count($cases) . " HTTP requests).\n";
} finally {
    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
