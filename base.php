<?php  declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

function getBotToId($update) : int {
    if (isset($update['message']['to_id'])) {
        switch ($update['message']['to_id']['_']) {
            case 'peerChannel': return intval('-100'.strval($update['message']['to_id']['channel_id']));
            case 'peerChat':    return                 -1 * $update['message']['to_id']['chat_id'];
            case 'peerUser':    return                      $update['message']['to_id']['user_id'];
        }
    }
    return 0;
}

class EventHandler extends \danog\MadelineProto\EventHandler
{
    const ADMIN = "webwarp"; // Change this (to the Username or ID of the bot admin)

    private $start;

    public function __construct(?\danog\MadelineProto\APIWrapper $API)
    {
        parent::__construct($API);
        $this->start = time();
    }

    private $owner;
    public function setOwner($owner) {
        $this->owner = $owner;
    }
    public function getOwner() {
        return $this->owner;
    }

    public function getReportPeers()
    {
        return [self::ADMIN];
    }

function toJSON($var) {
    $json = json_encode($var, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json == '') {
        $json = var_export($var, true);
    }
    return $json;
}

    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if ($update['message']['_'] === 'messageEmpty') {
            return;
        }
        $msgOrig   = isset($update['message']['message']) ? trim($update['message']['message']) : null;
        $msg       = $msgOrig? strtolower($msgOrig) : null;
        $messageId = isset($update['message']['id'])        ? $update['message']['id']         : 0;
        $fromId    = isset($update['message']['from_id'])   ? $update['message']['from_id']    : 0;
        $replyToId = isset($update['message']['reply_to_msg_id'])?$update['message']['reply_to_msg_id']:0;
        $isOutward = isset($update['message']['out'])       ? $update['message']['out']        : false;
        $peerType  = isset($update['message']['to_id']['_'])? $update['message']['to_id']['_'] : '';
        $peer      = isset($update['message']['to_id'])     ? $update['message']['to_id']      : '';
        $byOwner   = $fromId === $this->owner['id'] && $msg;

        //  log the messages the owner or is a reply to a post published by the owner.
        if($fromId === $this->owner['id'] || $replyToId == $this->owner['id']) {
           $this->logger(self::toJSON($update));
        }

        if($byOwner && strlen($msg) >= 6 && substr($msg, 0, 6) === 'robot ') {
            $param = trim(substr($msg, 5));
            switch($param) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    =>
                            "<b>Robot Instructions:</b><br>".
                            "<br>".
                            ">> <b>robot help</b><br>".
                            "   To print the robot commands' help.<br>".
                            ">> <b>robot status</b><br>".
                            "   To query the status of the robot.<br>".
                            ">> <b>robot uptime</b><br>".
                            "   To query the robot's uptime.<br>" .
                            ">> <b>robot memory</b><br>".
                            "   To query the robot's memory usage.<br>" .
                            ">> <b>robot restart</b><br>".
                            "   To restart the robot.<br>".
                            ">> <b>robot stop</b><br>".
                            "   To stop the script.<br>".
                            ">> <b>robot logout</b><br>".
                            "   To terminate the robot's session.<br>",
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                case 'status':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is online!',
                    ]);
                    break;
                case 'age':
                    $age     = time() - $this->start;
                    $days    = floor($age  / 86400);
                    $hours   = floor(($age / 3600) % 3600);
                    $minutes = floor(($age / 60) % 60);
                    $seconds = $age % 60;
                    $ageStr = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Robot's uptime is: ".$ageStr."."
                    ]);
                    break;
                case 'memory':
                    $memUsage = memory_get_usage(true);
                    if ($memUsage < 1024) {
                        $memUsage .= ' bytes';
                    } elseif ($memUsage < 1048576) {
                        $memUsage = round($memUsage/1024,2) . ' kilobytes';
                    } else {
                        $memUsage = round($memUsage/1048576,2) . ' megabytes';
                    }
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Robot's uptime is: ".$ageStr."."
                    ]);
                    //">> <b>robot memory</b><br>".
                    //"   To query the robot's memory consumption.<br>" .
                    break;
                case 'restart':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Restarting the robot ...',
                    ]);
                    yield $this->logger('The robot re-started by the owner.');
                    $this->restart();
                    break;
                case 'logout':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is logging out. ...',
                    ]);
                    $this->logger('the robot is logged out by the owner.');
                    $this->logout();
                case 'stop':
                    $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Stopping the robot ...',
                    ]);
                    $this->logger('The robot is stopped by the owner.');
                    //\danog\MadelineProto\Magic::shutdown();
                    $this->stop();
                    exit();
            }
        }

        // Function: In response to a message containing the word time, and nothing else,
        //           the time-request is replaced by the time at the moment.
        if($byOwner && $msg === 'time') {
            yield $this->messages->editMessage([
                'peer'    => $update,
                'id'      => $messageId,
                'message' => date('H:i:s')
            ]);
        }

        // Function: In response to a message containing the word 'ping', and nothing else,
        //           the message is replied by a message containing the word 'pong'.
        if($byOwner && $msg === 'ping') {
            $updates = yield $this->messages->sendMessage([
                'peer'            => $peer,
                'reply_to_msg_id' => $messageId,
                'message'         => 'pong'
            ]);
        }
    }
}

if (file_exists('MadelineProto.log')) {unlink('MadelineProto.log');}
$settings['logger']['logger_level'] = \danog\MadelineProto\Logger::ULTRA_VERBOSE;
$settings['logger']['logger']       = \danog\MadelineProto\Logger::FILE_LOGGER;

$MadelineProto = new \danog\MadelineProto\API('session.madeline', $settings);

$genLoop = new \danog\MadelineProto\Loop\Generic\GenericLoop(
    $MadelineProto,
    function () use($MadelineProto) {
        $MadelineProto->logger('Time is '.date('H:i:s').'!');
        return 60; // Repeat 60 seconds later
    },
    'Repeating Loop'
);

while (true) {
    try {
        $MadelineProto->async(true);
        $MadelineProto->loop(function () use ($MadelineProto) {
            $owner = yield $MadelineProto->start();
            yield $MadelineProto->setEventHandler('\EventHandler');
            yield $MadelineProto->getEventHandler('\EventHandler')->setOwner($owner);
        });
        $genLoop->start();
        $MadelineProto->loop();
    } catch (\Throwable $e) {
        try {
            $MadelineProto->logger("Surfaced: $e");
            $MadelineProto->getEventHandler(['async' => false])->report("Surfaced: $e");
        }
        catch (\Throwable $e) {
        }
    }
}