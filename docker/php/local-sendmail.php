<?php

$directory = '/tmp/skinsyntax-mail';
if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
    exit(1);
}

$path = tempnam($directory, 'message-');
if ($path === false) {
    exit(1);
}

$message = file_get_contents('php://stdin');
if ($message === false || file_put_contents($path, $message) !== strlen($message)
    || !rename($path, $path . '.eml')) {
    unlink($path);
    exit(1);
}
