<?php
// Scripted one-shot SMTP peer for contact_test.php: php fake_smtp.php <transcriptFile>
// Prints its port, accepts one connection, answers like a plain server that offers AUTH LOGIN, writes everything the client sent.
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
echo substr(strrchr(stream_socket_get_name($srv, false), ':'), 1) . "\n";
$c = stream_socket_accept($srv, 10);
$log = '';
$say = function (string $s) use ($c) {
    fwrite($c, $s . "\r\n");
};
$say('220 fake ESMTP');
$data = false;
$auth = 0;
while (($line = fgets($c)) !== false) {
    $log .= $line;
    if ($data) {
        if ($line === ".\r\n") {
            $data = false;
            $say('250 queued');
        }
        continue;
    }
    $cmd = strtoupper(trim($line));
    if ($auth > 0) {
        $say(--$auth > 0 ? '334 UGFzc3dvcmQ6' : '235 ok');
    } elseif (str_starts_with($cmd, 'EHLO')) {
        $say('250-fake');
        $say('250 AUTH LOGIN');
    } elseif ($cmd === 'AUTH LOGIN') {
        $auth = 2;
        $say('334 VXNlcm5hbWU6');
    } elseif ($cmd === 'DATA') {
        $data = true;
        $say('354 go');
    } elseif ($cmd === 'QUIT') {
        $say('221 bye');
        break;
    } else {
        $say('250 ok');
    }
}
file_put_contents($argv[1], $log);
