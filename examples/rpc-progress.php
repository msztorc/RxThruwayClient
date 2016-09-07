<?php

use Rx\Observer\CallbackObserver;
use Rx\Thruway\Client;

require __DIR__ . '/../vendor/autoload.php';

$client = new Client('ws://127.0.0.1:9090', "realm1");

$client->call('com.myapp.example', [1234], [], ["receive_progress" => true])
    ->take(10)
    ->subscribe(new CallbackObserver(
            function ($res) {
                list($args, $argskw, $details) = $res;

                echo "Call result: ", $args[0], PHP_EOL;
            },
            function (Exception $e) {
                echo "Call error: ", $e->getMessage(), PHP_EOL;
            },
            function () {
                echo "Call completed", PHP_EOL;
            })
    );