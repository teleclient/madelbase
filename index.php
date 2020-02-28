<?php

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    const ADMIN = "webwarp"; // Change this (to the Username or ID of bot admin)

    public function __construct(?\danog\MadelineProto\APIWrapper $API)
    {
        parent::__construct($API);
    }

    public function getReportPeers()
    {
        return [self::ADMIN];
    }

    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        $this->processScriptCommands($update);

        $res = json_encode($update, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($res == '') {
            $res = var_export($update, true);
        }
        //yield $this->echo($res.PHP_EOL); // Uncomment for detailed output.
    }

  /*
    Usage: "script exit"   To stop the script.
           "script logout" To log out of the session
           The commands must be issued by the owner of the robot.
    */
    private function processScriptCommands($update) {
        if(isset($update['message']['out'])) {
            $msg = $update['message']['message']? trim($update['message']['message']) : null;
            if($msg && strlen($msg) >= 7 && strtolower(substr($msg, 0, 7)) === 'script ') {
                $param = strtolower(trim(substr($msg, 6)));
                switch($param) {
                    case 'logout':
                        $this->logout();
                        echo('Successfully logged out by the owner.'.PHP_EOL);
                    case 'exit':
                        echo('Robot is stopped by the owner.'.PHP_EOL);
                        \danog\MadelineProto\Magic::shutdown();
                }
            }
        }
        return false;
    }
}

if (file_exists('MadelineProto.log')) {unlink('MadelineProto.log');}
$settings['logger']['logger_level'] = \danog\MadelineProto\Logger::ULTRA_VERBOSE;
$settings['logger']['logger']       = \danog\MadelineProto\Logger::FILE_LOGGER;
$MadelineProto = new \danog\MadelineProto\API('session.madeline', $settings);
$MadelineProto->async(true);

while (true) {
    try {
        $MadelineProto->loop(function () use ($MadelineProto) {
            yield $MadelineProto->start();
            yield $MadelineProto->setEventHandler('\EventHandler');
        });
        $genLoop = new \danog\MadelineProto\Loop\Generic\GenericLoop(
            $MadelineProto,
            function () use($MadelineProto) {
                $time = date('H:i');
                $MadelineProto->echo("Time is $time!".PHP_EOL);
                return 30;
            },
            'Generic Loop'
        );
        $genLoop->start();
        $MadelineProto->loop();
    } catch (\Throwable $e) {
        try {
            $MadelineProto->logger("Surfaced: $e");
            $MadelineProto->getEventHandler(['async' => false])->report("Surfaced: $e");
        } catch (\Throwable $e) {
        }
    }
}
