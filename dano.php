<?php

use Amp\Http\Server\HttpServer;
use danog\MadelineProto\API;
use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\MTProtoTools\Files;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Tools;
use League\Uri\Contracts\UriException;

// $MadelineProto = new \danog\MadelineProto\API('session.madeline');

$settings = [
    'logger' => [
        'logger_level' => 4
    ],
    'serialization' => [
        'serialization_interval' => 30
    ],
    'connection_settings' => [
        'media_socket_count' => [
            'min' => 20,
            'max' => 1000,
        ]
    ],
    'upload' => [
        'allow_automatic_upload' => false // IMPORTANT: for security reasons, upload by URL will still be allowed
    ],
];

$MadelineProto = new \danog\MadelineProto\API(($argv[1] ?? 'bot') . '.madeline', $settings);

$MadelineProto->async(true);
$MadelineProto->loop(function () use ($MadelineProto) {
    yield $MadelineProto->start();

    // You can also have an asynchronous get_updates (deprecated) loop in here, if you want to; 
    // just don't forget to use yield for all MadelineProto functions.
    //$a = yield $MadelineProto->messages->sendMedia([
    //    'peer'       => '@ja_support',
    //    'media'      => ['_' => 'inputMediaUploadedDocument',
    //    'file'       => $_GET['url'],
    //    'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'tmmmppp.txt']]],
    //    'message'    => 'message',
    //    'parse_mode' => 'Markdown'
    //]);

    $peer      = $_GET['chatID'];
    $peerId    = "@webwarp";
    $messageId = $_GET['messageID'];
    echo "a";

    // last Message ID
    $id = yield $MadelineProto->messages->sendMessage([
        'peer'    => $peerId,
        'message' => 'Preparing...'
    ]);

    $url = new \danog\MadelineProto\FileCallback(
        $_GET['url'],
        function ($progress, $speed, $time) use ($peerId, $id) {
            $this->logger("Upload progress: $progress%");

            static $prev = 0;
            $now = \time();
            if ($now - $prev < 10 && $progress < 100) {
                return;
            }
            $prev = $now;
            try {
                $ad = yield $this->messages->sendMessage([
                    'peer'    => '@ja_support',
                    'message' => "Upload progress: $progress%\nSpeed: $speed mbps\nTime elapsed since start: $time"
                ]);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
            }
        }
    );

     $MadelineProto->messages->sendMessage([
         'peer'    => '@ja_support',
         'message' => "Hi!\nThanks for creating MadelineProto! <3"
    ]);

    $a = yield $MadelineProto->messages->sendMedia(
        [
            'peer'       => $peerId,
            'media'      => [
                '_' => 'inputMediaUploadedDocument',
                'file' => $_GET['url'],
                'attributes' => [
                    ['_' => 'documentAttributeFilename', 'file_name' => 'asdname']
                ]
            ],
            'message'    => 'Powered by @MadelineProto!',
            'parse_mode' => 'Markdown'
        ]
    );
});