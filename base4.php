<?php  declare(strict_types=1);
//date_default_timezone_set('Asia/Tehran');
date_default_timezone_set('America/Los_Angeles');

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    const ADMIN = "webwarp"; // Change this (to the Username or ID of the bot admin)

    private $start;

    public function __construct(?\danog\MadelineProto\APIWrapper $API)
    {
        parent::__construct($API);
        $this->start = time();
    }

    private $me;
    public function setMe($me) {
        $this->owner = $me;
    }
    public function getMe() {
        return $this->me;
    }

    public function getReportPeers()
    {
        return [self::ADMIN];
    }

    public static function toJSON($var) {
        $json = json_encode($var, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json == '') {
            $json = var_export($var, true);
        }
        return $json;
    }
    /**
     * Called from within setEventHandler, can contain async calls for initialization of the bot
     *
     * @return void
     */
    public function onStart()
    {
    }

    public static function getBotToId($update) : int {
        if (isset($update['message']['to_id'])) {
            switch ($update['message']['to_id']['_']) {
                case 'peerChannel': return intval('-100'.strval($update['message']['to_id']['channel_id']));
                case 'peerChat':    return                 -1 * $update['message']['to_id']['chat_id'];
                case 'peerUser':    return                      $update['message']['to_id']['user_id'];
            }
        }
        return 0;
    }

    public function onUpdateNewChannelMessage($update)
    {
        if ($update['message']['_'] === 'messageEmpty') {
            return;
        }
        if($update['_'] === 'updateNewChannelMessage') {
            yield $this->processBuiltinFeatures($update);
        }

        yield $this->onUpdateNewMessage($update);
    }

    public function onUpdateNewMessage($update)
    {
        if ($update['message']['_'] === 'messageEmpty' || $update['message']['out']?? false) {
            return;
        }
        if($update['_'] === 'updateNewMessage') {
            yield $this->processBuiltinFeatures($update);
        }
        $msgOrig   = isset($update['message']['message']) ? trim($update['message']['message']) : null;
        $msg       = $msgOrig? strtolower($msgOrig) : null;
        $messageId = isset($update['message']['id'])        ? $update['message']['id']         : 0;
        $fromId    = isset($update['message']['from_id'])   ? $update['message']['from_id']    : 0;
        $replyToId = isset($update['message']['reply_to_msg_id'])?$update['message']['reply_to_msg_id']:null;
        $isOutward = isset($update['message']['out'])       ? $update['message']['out']        : false;
        $peerType  = isset($update['message']['to_id']['_'])? $update['message']['to_id']['_'] : '';
        $peer      = isset($update['message']['to_id'])     ? $update['message']['to_id']      : '';
        $meID      = $this->me['id'];
        $byOwner   = $fromId === $meID && $msg;

        // Your code here
    }

    public function processBuiltinFeatures($update)
    {
        $MadelineProto = $this;

        echo($this->toJSON($update).PHP_EOL);

        $data = [];
        $error = function ($e, $chatID = NULL) use (&$MadelineProto) {
            $MadelineProto->log($e, [], 'error');
            if (isset($chatID) && $MadelineProto->settings['send_errors']) {
                try {
                    $MadelineProto->messages->sendMessage(
                    [
                        'peer'    => $chatID,
                        'message' => '<b>' . $MadelineProto->strings['error'] . '</b>' .
                                     '<code>' . $e->getMessage() . '</code>',
                        'parse_mode' => 'HTML'
                    ],
                    [
                        'async' => true
                    ]
                );
                } catch (\Throwable $e) { }
            }
        };
        //$parseUpdate = function ($update) use (&$MadelineProto, &$error) {
            echo('Hello!'.PHP_EOL);
            $result = [
                'chatID'       => null,
                'userID'       => null,
                'msgid'        => null,
                'type'         => null,
                'name'         => null,
                'username'     => null,
                'chatusername' => null,
                'title'        => null,
                'msg'          => null,
                'info'         => null,
                'update'       => $update
            ];
            try {
                if (isset($update['message'])) {
                    if (isset($update['message']['from_id'])) {
                        $result['userID'] = $update['message']['from_id'];
                    }
                    if (isset($update['message']['id'])) {
                        $result['msgid'] = $update['message']['id'];
                    }
                    if (isset($update['message']['message'])) {
                        $result['msg'] = $update['message']['message'];
                    }
                    if (isset($update['message']['to_id'])) {
                        $result['info']['to'] = yield $MadelineProto->getInfo($update['message']['to_id'],
                                                                              ['async' => false]);
                    }
                    if (isset($result['info']['to']['bot_api_id'])) {
                        $result['chatID'] = $result['info']['to']['bot_api_id'];
                    }
                    if (isset($result['info']['to']['type'])) {
                        $result['type'] = $result['info']['to']['type'];
                    }
                    if (isset($result['userID'])) {
                        $result['info']['from'] = yield $MadelineProto->getInfo($result['userID'],
                                                                                ['async' => false]);
                    }
                    if (isset($result['info']['to']['User']['self']) && isset($result['userID']) &&
                        $result['info']['to']['User']['self'])
                    {
                        $result['chatID'] = $result['userID'];
                    }
                    if (isset($result['type']) and $result['type'] == 'chat') {
                        $result['type'] = 'group';
                    }
                    if (isset($result['info']['from']['User']['first_name'])) {
                        $result['name'] = $result['info']['from']['User']['first_name'];
                    }
                    if (isset($result['info']['to']['Chat']['title'])) {
                        $result['title'] = $result['info']['to']['Chat']['title'];
                    }
                    if (isset($result['info']['from']['User']['username'])) {
                        $result['username'] = $result['info']['from']['User']['username'];
                    }
                    if (isset($result['info']['to']['Chat']['username'])) {
                        $result['chatusername'] = $result['info']['to']['Chat']['username'];
                    }
                }
            } catch (\Throwable $e) {
                $error($e);
            }
            $json = json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($json == '') {
                $json = var_export($result, true);
            }
            echo($json);
        //    return $result;
        //};
        //$result = $parseUpdate($update);

        $msgTime = isset($update['message']['time'])? $update['message']['time'] : \time();
        if(\time() - $msgTime > 10) {
            return;
        }

        /*
        $update       - received update
        $msg          - message
        $chatID       - chat id
        $userID       - user id
        $msgid        - message id
        $type         - user, bot, group, supergroup, channel
      //$name         - user's name
      //$username     - user's username
      //$title        - chat title
      //$chatusername - chat username
      //$info         - information of the user and the chat
        $me           - userbot informations
        */
        $result = [
            'chatID'       => null,
            'userID'       => null,
            'msgid'        => null,
            'type'         => null,
            'name'         => null,
            'username'     => null,
            'chatusername' => null,
            'title'        => null,
            'msg'          => null,
            'info'         => null,
            'update'       => $update
        ];

        $msgOrig   = isset($update['message']['message']) ? trim($update['message']['message']) : null;
        $msg       = $msgOrig? strtolower($msgOrig) : null;
        $chatID    = isset($update['message']['to_id'])? $update['message']['to_id'] : '';
        $userID    = isset($update['message']['from_id'])? $update['message']['from_id'] : null;
        $msgid     = isset($update['message']['id'])? $update['message']['id'] : 0;
        $type      = isset($update['message']['to_id']['_'])? $update['message']['to_id']['_'] : '';
      //$name
      //$username
      //$chatusername
      //$title
      //$info
        $replyToID = isset($update['message']['reply_to_msg_id'])?$update['message']['reply_to_msg_id']:null;
        $isOutward = isset($update['message']['out'])? $update['message']['out'] : false;
        $meID      = $this->me['id'];
        $byMe      = $meID === $userID && $msg;

        //  log the messages of the owner or any reply to a post published by the owner.
        if(in_array($meID, [$userID, $replyToID])) {
           $this->logger(self::toJSON($update));
        }

        if($byMe && strlen($msg) >= 6 && substr($msg, 0, 6) === 'robot ') {
            $param = trim(substr($msg, 5));
            switch($param) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $chatID,
                        'id'         => $msgid,
                        'message'    =>
                            "<b>Robot Instructions:</b><br>".
                            "<br>".
                            ">> <b>robot help</b><br>".
                            "     To print the robot commands' help.<br>".
                            ">> <b>robot status</b><br>".
                            "     To query the status of the robot.<br>".
                            ">> <b>robot uptime</b><br>".
                            "     To query the robot's uptime.<br>" .
                            ">> <b>robot restart</b><br>".
                            "     To restart the robot.<br>".
                            ">> <b>robot stop</b><br>".
                            "     To stop the script.<br>".
                            ">> <b>robot logout</b><br>".
                            "     To terminate the robot's session.<br>",
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                case 'status':
                    yield $this->messages->editMessage([
                        'peer'    => $chatID,
                        'id'      => $msgid,
                        'message' => 'The robot is online!',
                    ]);
                    break;
                case 'uptime':
                    $age = time() - $this->start;
                    $days    = floor($age / 86400);
                    $hours   = floor(($age / 3600) % 3600);
                    $minutes = floor(($age / 60) % 60);
                    $seconds = $age % 60;
                    $ageStr = sprintf("d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
                    yield $this->messages->editMessage([
                        'peer'    => $chatID,
                        'id'      => $msgid,
                        'message' => "Robot's uptime is: ".$ageStr."."
                    ]);
                    break;
                case 'stop':
                    $this->messages->editMessage([
                        'peer'    => $chatID,
                        'id'      => $msgid,
                        'message' => 'Restarting the robot ...',
                    ]);
                    $this->logger('Stopping the robot ...');
                    $this->stop();
                    break;
                case 'restart':
                    $this->messages->editMessage([
                        'peer'    => $chatID,
                        'id'      => $msgid,
                        'message' => 'Restarting the robot ...',
                    ]);
                    $this->logger('The robot re-started by the owner.');
                    $this->restart();
                    break;
                case 'logout':
                    yield $this->messages->editMessage([
                        'peer'    => $chatID,
                        'id'      => $msgid,
                        'message' => 'The robot is logging out. ...',
                    ]);
                    $this->logger('the robot is logged out by the owner.');
                    $this->logout();
                case 'shutdown':
                    $this->messages->editMessage([
                        'peer'    => $chatID,
                        'id'      => $msgid,
                        'message' => 'Shutting down the robot ...',
                    ]);
                    $this->logger('The robot is shotdown by the owner.');
                    \danog\MadelineProto\Magic::shutdown();
            }
        }

        // Function: In response to a message containing the word time, and nothing else,
        //           the time-request is replaced by the time at the moment.
        if($byMe && $msg === 'time') {
            yield $this->messages->editMessage([
                'peer'    => $update,
                'id'      => $msgid,
                'message' => date('H:i:s')
            ]);
        }

        // Instructions:
        // ping
        //     In response to a message containing the word 'ping',
        //     and nothing else, the message is replied by a message
        //     containing the word 'pong'.
        // ping 15
        //    Same as ping, but the word 'pong' is sent after 15 seconds.
        //
        if ($byMe && strncasecmp($msg, 'ping', 4) === 0) {
            $param = trim(substr($msg, 4));
            $delay = !ctype_digit($param)? 0 : intval($param);
            yield $this->messages->sendMessage([
                'peer'            => $chatID,
                'reply_to_msg_id' => $msgid,
                'schedule_date'   => time() + $delay,
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

//while (true) {
    try {
        $MadelineProto->async(true);
        $MadelineProto->loop(function () use ($MadelineProto) {
            $owner = yield $MadelineProto->start();
            yield $MadelineProto->setEventHandler('\EventHandler');
            $handler = yield $MadelineProto->getEventHandler();
            $handler->setOwner($owner);
            yield $MadelineProto->messages->sendMessage([
                'peer'    => $owner['id'],
                'message' => $owner['username'].' successfully started!'
            ]);
        });
        $genLoop->start();
        $MadelineProto->loop();
    } catch (\Throwable $e) {
        $MadelineProto->logger("Surfaced: $e");
        $MadelineProto->getEventHandler()->report("Surfaced: $e");
    }
//}


/*
// startAndLoop
while (true) {
    try {
        \danog\MadelineProto\Tools::wait(
            // startAndLoopAsync
            function (string $eventHandler) use ($MadelineProto)
            {
                $MadelineProto->async(true);
                while (true) {
                    try {
                        yield $MadelineProto->start();
                        yield $MadelineProto->setEventHandler($eventHandler);
                        return yield from $MadelineProto->API->loop();
                    } catch (\Throwable $e) {
                        $MadelineProto->logger->logger((string) $e, \danog\MadelineProto\Logger::FATAL_ERROR);
                        $MadelineProto->report("Surfaced: $e");
                    }
                }
            }
        );
    } catch (\Throwable $e) {
        $MadelineProto->logger->logger((string) $e, \danog\MadelineProto\Logger::FATAL_ERROR);
        $MadelineProto->report("Surfaced: $e");
    }
}
*/
