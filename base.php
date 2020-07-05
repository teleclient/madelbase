<?php  declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

function toJSON($var, bool $pretty = true): string {
    $opts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '')? $json : var_export($var, true);
    return $json;
}

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Loop\Generic\GenericLoop;


class EventHandler extends \danog\MadelineProto\EventHandler
{
    const ADMIN = "webwarp"; // Change this (to the Username or ID of the bot admin)

    private $robotID;
    private $start;

    public function __construct(?\danog\MadelineProto\APIWrapper $API)
    {
        parent::__construct($API);
    }

    public function getReportPeers()
    {
        return [self::ADMIN];
    }

    public function getRobotID(): int
    {
        return $this->robotID;
    }

    // Called on startup, can contain async calls for initialization of the bot
    public function onStart(): \Generator
    {
        $robot = yield $this->getSelf();
        $this->robotID = $robot['id'];
        $this->start   = time();
        yield $this->messages->sendMessage([
            'peer'    => $this->robotID,
            'message' => "Robot just started."
        ]);

    }


    public function onUpdateEditMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
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
        $msgOrig   = $update['message']['message']?? null;
        $msg       = $msgOrig? strtolower($msgOrig) : null;
        $messageId = $update['message']['id']?? 0;
        $fromId    = $update['message']['from_id']?? 0;
        $replyToId = $update['message']['reply_to_msg_id']?? 0;
        $isOutward = $update['message']['out']?? false;
        $peerType  = $update['message']['to_id']['_']?? '';
        $peer      = $update['message']['to_id']?? null;
        $byRobot   = $fromId === $this->robotID && $msg;

        //  log the messages of the robot a reply to a message published by the robot.
        if($fromId === $this->robotID || $replyToId == $this->robotID) {
           yield $this->logger(toJSON($update), Logger::ERROR);
        } else {
            //yield $this->logger(toJSON($update, false), Logger::ERROR);
        }

        if($byRobot && strlen($msg) >= 6 && substr($msg, 0, 6) === 'robot ') {
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
                case 'uptime':
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
                        'message' => "Robot's uptime is: ".$memUsage."."
                    ]);
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
                        'message' => 'Robot is stopping ...',
                    ]);
                    break;
            } // enf of the command switch
        } // end of the commander qualification check

        //Function: Finnish executing the Stop command.
        if($byRobot && $msgOrig === 'Robot is stopping ...') {
            $this->stop();
        }
    } // end of function
} // end of the class

if (file_exists('MadelineProto.log')) {unlink('MadelineProto.log');}
$settings['logger']['logger_level'] = Logger::ERROR;
$settings['logger']['logger']       = Logger::FILE_LOGGER;

$MadelineProto = new API('session.madeline', $settings);
$MadelineProto->async(true);

$genLoop = new GenericLoop(
    $MadelineProto,
    function () use($MadelineProto) {
        $msg = 'Time is '.date('H:i:s').'!';
        yield $MadelineProto->logger($msg, Logger::ERROR);
        $eh = $MadelineProto->getEventHandler();
        $robotID = $eh->getRobotID();
        yield $MadelineProto->messages->sendMessage([
            'peer'    => $robotID,
            'message' => $msg
        ]);
        return 60; // Repeat 60 seconds later
    },
    'Repeating Loop'
);

$loopOnce = true;
do {
    try {
        $genLoop->start();
        $MadelineProto->loop(function () use ($MadelineProto) {
            $robot = yield $MadelineProto->start();
            yield $MadelineProto->logger(toJSON($robot), Logger::ERROR);
            yield $MadelineProto->setEventHandler('\EventHandler');
            yield $MadelineProto->loop();
        });
        sleep(5);
        break;
    } catch (\Throwable $e) {
        try {
            $MadelineProto->logger("Surfaced: $e");
            $MadelineProto->getEventHandler(['async' => false])->report("Surfaced: $e");
            break;
        }
        catch (\Throwable $e) {
        }
    }
} while (!$loopOnce);

exit();