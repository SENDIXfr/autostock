<?php

function checkCron($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$cases = [
    [[], 403],
    [['token' => 'wrong'], 403],
    [['token' => ['secret-token']], 403],
    [['token' => 'secret-token'], 200],
    [['token' => 'secret-token', 'id_shop' => '2'], 200],
    [['token' => 'secret-token', 'id_shop' => ['1']], 400],
    [['token' => 'secret-token', 'id_shop' => '2garbage'], 400],
    [['token' => 'secret-token', 'id_shop' => 0], 400],
    [['token' => 'secret-token', 'id_shop' => 3], 400],
    [['token' => 'secret-token', 'id_shop' => 4], 409],
    [['token' => 'secret-token', 'id_shop' => 5], 500],
];

foreach ($cases as $case) {
    // Send structured arguments over stdin to avoid OS-specific shell quoting.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cron-request.php');
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    checkCron(is_resource($process), 'Unable to start PHP subprocess.');
    fwrite($pipes[0], json_encode($case[0]));
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $status = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $response = json_decode($body, true);
    checkCron($exitCode === 0 && $status === (string) $case[1], 'Unexpected cron status: ' . $status . ' / ' . $body);
    checkCron(is_array($response) && $response['success'] === ($case[1] === 200), 'Cron must always return valid JSON.');
    checkCron(strpos($body, 'private details') === false, 'Internal exception details must not leak in cron responses.');
    if ($case[1] === 200) {
        checkCron($response['shop'] === (isset($case[0]['id_shop']) ? (int) $case[0]['id_shop'] : 1), 'The requested shop must be used.');
        checkCron($response['disabled'] === 2 && $response['enabled'] === 1, 'Cron counters are incorrect.');
    }
}

echo "Cron controller tests passed (" . count($cases) . " requests).\n";
