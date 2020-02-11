<?php

use \danog\MadelineProto\Logger;

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    private $self;

    public function __construct($MadelineProto)
    {
        parent::__construct($MadelineProto);
    }

    public function setSelf(array $self) {
        $this->self = $self;
    }

    public function report(string $message)
    {
        try {
            $this->messages->sendMessage([
                'peer'    => $this->self['id'],
                'message' => $message
            ]);
        } catch (\Throwable $e) {
            $this->logger("While reporting: $e", Logger::FATAL_ERROR);
        }
    }

    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if (isset($update['message']['out']) && $update['message']['out']) {
            $this->processScriptCommands($update);
            return;
        }

        $res = json_encode($update, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($res == '') {
            $res = var_export($update, true);
        }
        yield $this->echo($res.PHP_EOL);

        /*
        try {
            yield $this->messages->sendMessage(['
                peer'             => $update,
                'message'         => $res,
                'reply_to_msg_id' => $update['message']['id']
            ]);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield $this->messages->sendMessage([
                'peer'    => '@webwarp',
                'message' => (string) $e
            ]);
        }
        */

        /*
        try {
            if (isset($update['message']['media']) &&
                ($update['message']['media']['_'] == 'messageMediaPhoto' ||
                 $update['message']['media']['_'] == 'messageMediaDocument'))
            {
                $time = microtime(true);
                $file = yield $this->downloadToDir($update, '/tmp');
                yield $this->messages->sendMessage([
                    'peer'    => $update,
                    'message' => 'Downloaded to '.$file.' in '.(microtime(true) - $time).' seconds',
                    'reply_to_msg_id' => $update['message']['id']]);
            }
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            yield $this->messages->sendMessage([
                'peer'    => '@webwarp',
                'message' => (string) $e
            ]);
        }
        */
    }

  /*
    Usage: "script exit"   To stop the script.
           "script logout" To log out of the session
           The commands must be issued by the owner of the userbot.
    */
    private function processScriptCommands($update) {
        if(isset($update['message']['out'])) {
            $msg = $update['message']['message']? trim($update['message']['message']) : null;
            if($msg && strlen($msg) >= 7 && strtolower(substr($msg, 0, 7)) === 'script ') {
                $param = strtolower(trim(substr($msg, 6)));
                switch($param) {
                    case 'logout':
                        $this->logout();
                        echo('Successfully logged out.'.PHP_EOL);
                    case 'exit':
                        echo('Robot is stopped.'.PHP_EOL);
                        \danog\MadelineProto\Shutdown::addCallback(function () {}, 1);
                        exit();
                }
            }
        }
        return false;
    }
}


if (file_exists('MadelineProto.log')) {unlink('MadelineProto.log');}
$settings['logger']['logger_level'] = Logger::ULTRA_VERBOSE;
$settings['logger']['logger']       = Logger::FILE_LOGGER;
$MadelineProto = new \danog\MadelineProto\API('session.madeline', $settings);
$MadelineProto->async(true);

while (true) {
    try {
        $MadelineProto->loop(function () use ($MadelineProto) {
            yield $MadelineProto->start();
            yield $MadelineProto->setEventHandler('\EventHandler'); // or: (EventHandler::class)
            $self = yield $MadelineProto->getSelf();
            $MadelineProto->getEventHandler()->setSelf($self);
        });
        $MadelineProto->loop();
    } catch (\Throwable $e) {
        try {
            $MadelineProto->logger("Surfaced: $e");
            $MadelineProto->getEventHandler(['async' => false])->report("Surfaced: $e");
        } catch (\Throwable $e) {
        }
    }
}