<?php  declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

if(!file_exists('data')) {
    mkdir('data');
}
if(!file_exists('./data/loopstate.json')) {
    file_put_contents('./data/loopstate.json', 'off');
}

function getConfig(): array
{
    if(file_exists('config.php')) {
        $config = include 'config.php';
        $config['delete_log']   = $config['delete_log']??  true;
        $config['max_restarts'] = $config['max_restart']?? 1;
        return $config;
    } else {
        return [
            'delete_log'   => true,
            'max_restarts' => 1
        ];
    }
}

function getCredentials(): array
{
    if(file_exists('credentials.php')) {
        return include 'credentials.php';
    } else {
        return [];
    }
}

function getSettings(): array
{
    if(file_exists('settings.php')) {
        return include 'settings.php';
    } else {
        return [];
    }
}

function secondsToNexMinute(): int
{
    $now   = hrtime()[0]; // time();
    $next  = intdiv($now + 60, 60) * 60;
    $delay = $next - $now;
    $delay = $delay > 60? 60 : $delay;
    return $delay; // in sec
}

function toJSON($var, bool $pretty = true): string {
    $opts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '')? $json : var_export($var, true);
    return $json;
}


function parseCommand(string $msg, string $prefixes = '!/', int $maxParams = 3): array
{
    $command = ['prefix' => '', 'verb' => '', 'params' => []];
    $msg = trim($msg);
    if($msg && strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
        $verb = strtolower(substr(rtrim($msg), 1, strpos($msg.' ', ' ') - 1));
        if(ctype_alnum($verb)) {
            $command['prefix'] = $msg[0];
            $command['verb']   = $verb;
            $tokens = explode(' ', $msg, $maxParams + 1);
            for($i = 1; $i < count($tokens); $i++) {
                $command['params'][$i - 1] = trim($tokens[$i]);
            }
        }
    }
    return $command;
}


function safeStartAndLoop(int $maxRestarts, $MadelineProto, $genLoop = null): void {
    $restarts = 0;
    do {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $genLoop) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler('\EventHandler');
                if($genLoop !== null) {
                    /*yield*/ $genLoop->start(); // Do NOT use yield.
                }
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
    } while ($restarts++ < $maxRestarts);
}


use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Loop\Generic\GenericLoop;

class EventHandler extends \danog\MadelineProto\EventHandler
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
        //$this->loopState   = false;
        $value = file_get_contents('data/loopstate.json');
        $this->loopState = $value === 'on'? true: false;
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
    public function setLoopState($loopState) {
        $this->loopState = $loopState;
        file_put_contents('data/loopstate.json', $loopState? 'on' : 'off');
    }


    public function onStart(): \Generator
    {
        $robot = yield $this->getSelf();
        $this->robotID = $robot['id'];

        if(isset($this->config['owner_id'])) {
            $this->ownerID = $this->config['owner_id'];
        }

        if(isset($this->config['report_peers'])) {
            foreach($this->config['report_peers'] as $reportPeer) {
                switch(strtolower($reportPeer)) {
                    case 'robot':
                        array_push($this->reportPeers, $this->robotID);
                        break;
                    case 'owner':
                        if(isset($this->ownerID) && $this->ownerID !== $this->robotID) {
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
        if ($update['message']['_'] === 'messageService' ||
            $update['message']['_'] === 'messageEmpty')
        {
            return;
        }
        if(!isset($update['message']['message'])) {
            yield $this->echo(toJSON($update).PHP_EOL);
            exit;
        }
        $msgOrig   = $update['message']['message']?? null;
        $msg       = $msgOrig? strtolower($msgOrig) : null;
        $messageId = $update['message']['id']?? 0;
        $fromId    = $update['message']['from_id']?? 0;
        $replyToId = $update['message']['reply_to_msg_id']?? 0;
        $isOutward = $update['message']['out']?? false;
        $peerType  = $update['message']['to_id']['_']?? '';
        $peer      = $update['message']['to_id']?? null;
        $byRobot   = $fromId    === $this->robotID && $msg;
        $toRobot   = $replyToId === $this->robotID && $msg;

        $command = parseCommand($msgOrig);
        $verb    = $command['verb']?? null;
        $params  = $command['params'];
        if($verb) {
            yield $this->echo(toJSON($command,  false).PHP_EOL);
        }

        //  log the messages of the robot, or a reply to a message sent by the robot.
        if($byRobot || $toRobot) {
           yield $this->logger(toJSON($update, false), Logger::ERROR);
        } else {
            //yield $this->logger(toJSON($update, false), Logger::ERROR);
        }

        if($byRobot && $verb) {
            switch($verb) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    =>
                            "<b>Robot Instructions:</b><br>".
                          //"<br>".
                            ">> <b>/help</b><br>".
                            "   To print the robot commands' help.<br>".
                            ">> <b>/loop</b> on/off/state<br>".
                            "   To query/change state of task repeater.<br>".
                            ">> <b>/status</b><br>".
                            "   To query the status of the robot.<br>".
                            ">> <b>/uptime</b><br>".
                            "   To query the robot's uptime.<br>" .
                            ">> <b>/memory</b><br>".
                            "   To query the robot's memory usage.<br>" .
                            ">> <b>/restart</b><br>".
                            "   To restart the robot.<br>".
                            ">> <b>/stop</b><br>".
                            "   To stop the script.<br>".
                            ">> <b>/logout</b><br>".
                            "   To terminate the robot's session.<br>".
                            "<br>".
                            "<b>**Valid prefixes are / and !</b><br>",
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                case 'loop':
                    $param = strtolower($params[0]??'');
                    if(($param === 'on' || $param === 'off' || $param === 'state') && count($params) === 1) {
                        $loopStatePrev = $this->getLoopState();
                        $loopState = $param === 'on'? true : ($param === 'off'? false : $loopStatePrev);
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => 'The loop is ' . ($loopState? 'ON' : 'OFF') . '!',
                        ]);
                        if($loopState !== $loopStatePrev) {
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

$config      = getConfig();
$settings    = getSettings();
$credentials = getCredentials();

if (file_exists('MadelineProto.log') && $config['delete_log']) {
    unlink('MadelineProto.log');
}
if($credentials['api_id']??null && $credentials['api_hash']??null) {
    $settings['app_info']['api_id']   = $credentials['api_id']  ??null;
    $settings['app_info']['api_hash'] = $credentials['api_hash']??null;
}

$MadelineProto = new API('session.madeline', $settings??[]);
$MadelineProto->async(true);

$genLoop = new GenericLoop(
    $MadelineProto,
    function () use($MadelineProto) {
        $eh = $MadelineProto->getEventHandler();
        if($eh->getLoopState()) {
            yield $MadelineProto->account->updateProfile([
                'about' => date('H:i:s')
            ]);
            //$robotID = $eh->getRobotID();
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