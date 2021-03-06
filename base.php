<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use \danog\MadelineProto\EventHandler as MadelEventHandler;

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';
require '../../../dev2php/packages/tools/functions.php';

if (!file_exists('data')) {
    mkdir('data');
}
if (!file_exists('./data/loopstate.json')) {
    file_put_contents('./data/loopstate.json', 'off');
}


class EventHandler extends MadelEventHandler
{
    private $config;
    private $robotID;
    private $ownerID;
    private $admins;
    private $startTime;
    private $reportPeers;
    private $loopState;

    public function __construct(?\danog\MadelineProto\APIWrapper $API)
    {
        parent::__construct($API);
        $this->startTime   = time();
        $this->config      = getConfig();
        $this->admins      = [];
        $this->reportPeers = [];
        $value = file_get_contents('data/loopstate.json');
        $this->loopState = $value === 'on' ? true : false;
    }

    public function getReportPeers()
    {
        return $this->reportPeers;
    }

    public function getRobotID(): int
    {
        return $this->robotID;
    }

    public function getLoopState(): bool
    {
        return $this->loopState;
    }
    public function setLoopState($loopState)
    {
        $this->loopState = $loopState;
        file_put_contents('data/loopstate.json', $loopState ? 'on' : 'off');
    }


    public function onStart(): \Generator
    {
        $robot = yield $this->getSelf();
        $this->robotID = $robot['id'];

        if (isset($this->config['owner_id'])) {
            $this->ownerID = $this->config['owner_id'];
        }

        if (isset($this->config['report_peers'])) {
            foreach ($this->config['report_peers'] as $reportPeer) {
                switch (strtolower($reportPeer)) {
                    case 'robot':
                        array_push($this->reportPeers, $this->robotID);
                        break;
                    case 'owner':
                        if (isset($this->ownerID) && $this->ownerID !== $this->robotID) {
                            array_push($this->reportPeers, $this->ownerID);
                        }
                        break;
                    default:
                        array_push($this->reportPeers, $reportPeer);
                        break;
                }
            }
        }

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
        if (
            $update['message']['_'] === 'messageService' ||
            $update['message']['_'] === 'messageEmpty'
        ) {
            return;
        }
        if (!isset($update['message']['message'])) {
            yield $this->echo(toJSON($update) . PHP_EOL);
            exit;
        }
        $msgOrig   = $update['message']['message'] ?? null;
        $msg       = $msgOrig ? strtolower($msgOrig) : null;
        $messageId = $update['message']['id'] ?? 0;
        $fromId    = $update['message']['from_id'] ?? 0;
        $replyToId = $update['message']['reply_to_msg_id'] ?? 0;
        $isOutward = $update['message']['out'] ?? false;
        $peerType  = $update['message']['to_id']['_'] ?? '';
        $peer      = $update['message']['to_id'] ?? null;
        $byRobot   = $fromId    === $this->robotID && $msg;
        $toRobot   = $replyToId === $this->robotID && $msg;

        $command = parseCommand($msgOrig);
        $verb    = $command['verb'] ?? null;
        $params  = $command['params'];

        //  log the messages of the robot, or a reply to a message sent by the robot.
        if ($byRobot || $toRobot) {
            yield $this->logger(toJSON($update, false), Logger::ERROR);
        } else {
            //yield $this->logger(toJSON($update, false), Logger::ERROR);
        }

        if ($byRobot && $verb) {
            switch ($verb) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    =>
                        "<b>Robot Instructions:</b><br>" .
                            //"<br>".
                            ">> <b>/help</b><br>" .
                            "   To print the robot commands' help.<br>" .
                            ">> <b>/loop</b> on/off/state<br>" .
                            "   To query/change state of task repeater.<br>" .
                            ">> <b>/status</b><br>" .
                            "   To query the status of the robot.<br>" .
                            ">> <b>/uptime</b><br>" .
                            "   To query the robot's uptime.<br>" .
                            ">> <b>/memory</b><br>" .
                            "   To query the robot's memory usage.<br>" .
                            ">> <b>/restart</b><br>" .
                            "   To restart the robot.<br>" .
                            ">> <b>/stop</b><br>" .
                            "   To stop the script.<br>" .
                            ">> <b>/logout</b><br>" .
                            "   To terminate the robot's session.<br>" .
                            "<br>" .
                            "<b>**Valid prefixes are / and !</b><br>",
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                case 'loop':
                    $param = strtolower($params[0] ?? '');
                    if (($param === 'on' || $param === 'off' || $param === 'state') && count($params) === 1) {
                        $loopStatePrev = $this->getLoopState();
                        $loopState = $param === 'on' ? true : ($param === 'off' ? false : $loopStatePrev);
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => 'The loop is ' . ($loopState ? 'ON' : 'OFF') . '!',
                        ]);
                        if ($loopState !== $loopStatePrev) {
                            $this->setLoopState($loopState);
                        }
                    } else {
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "The argument must be 'on', 'off, or 'state'.",
                        ]);
                    }
                    break;
                case 'status':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is online!',
                    ]);
                    break;
                case 'uptime':
                    $age     = time() - $this->startTime;
                    $days    = floor($age  / 86400);
                    $hours   = floor(($age / 3600) % 3600);
                    $minutes = floor(($age / 60) % 60);
                    $seconds = $age % 60;
                    $ageStr = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Robot's uptime is: " . $ageStr . "."
                    ]);
                    break;
                case 'memory':
                    $memUsage = memory_get_usage(true);
                    if ($memUsage < 1024) {
                        $memUsage .= ' bytes';
                    } elseif ($memUsage < 1048576) {
                        $memUsage = round($memUsage / 1024, 2) . ' kilobytes';
                    } else {
                        $memUsage = round($memUsage / 1048576, 2) . ' megabytes';
                    }
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Robot's uptime is: " . $memUsage . "."
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
        if ($byRobot && $msgOrig === 'Robot is stopping ...') {
            $this->stop();
        }
    } // end of function
} // end of the class

$config      = getConfig();
$settings    = getSettings();
$credentials = getCredentials();

if (file_exists('MadelineProto.log') && $config['delete_log']) {
    unlink('MadelineProto.log');
}
if ($credentials['api_id'] ?? null && $credentials['api_hash'] ?? null) {
    $settings['app_info']['api_id']   = $credentials['api_id']  ?? null;
    $settings['app_info']['api_hash'] = $credentials['api_hash'] ?? null;
}

$MadelineProto = new API('session.madeline', $settings ?? []);
$MadelineProto->async(true);

$genLoop = new GenericLoop(
    $MadelineProto,
    function () use ($MadelineProto) {
        $eventHandler = $MadelineProto->getEventHandler();
        if ($eventHandler->getLoopState()) {
            yield $MadelineProto->account->updateProfile([
                'about' => date('H:i:s')
            ]);
            //$robotID = $eventHandler->getRobotID();
            //yield $MadelineProto->messages->sendMessage([
            //    'peer'    => $robotID,
            //    'message' => date('H:i:s')
            //]);
            //yield $MadelineProto->logger($msg, Logger::ERROR);
        }
        $delay = yield secondsToNexMinute($MadelineProto);
        return $delay; // Repeat around 60 seconds later
    },
    'Repeating Loop'
);

$maxRestarts = $config['max_restarts'];
safeStartAndLoop($maxRestarts, $MadelineProto, $genLoop);

exit();
