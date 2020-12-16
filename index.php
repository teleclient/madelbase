<?php

declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');
ignore_user_abort(true);
set_time_limit(0);
error_reporting(E_ALL);                               // always TRUE
ini_set('ignore_repeated_errors', '1');               // always TRUE
ini_set('display_startup_errors', '1');
ini_set('display_errors',         '1');               // FALSE only in production or real server
ini_set('log_errors',             '1');               // Error logging engine
ini_set('error_log',              'php_errors.log');  // Logging file path

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

define("ROBOT_NAME",   'Base');
define('SESSION_FILE', 'session.madeline');

use \danog\MadelineProto\EventHandler as MadelineEventHandler;
use \danog\MadelineProto\Logger;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\API;
use \danog\MadelineProto\APIWrapper;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Magic;
use \danog\MadelineProto\Loop\Generic\GenericLoop;
use function\Amp\File\{get, put, exists};

function toJSON($var, bool $pretty = true): string
{
    if (isset($var['request'])) {
        unset($var['request']);
    }
    $opts = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty ? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '') ? $json : var_export($var, true);
    return $json;
}

function nowMilli()
{
    $mt = explode(' ', microtime());
    return ((int)$mt[1]) * 1000 + ((int)round($mt[0] * 1000));
}

function getUptime(int $start, int $end = 0): string
{
    $end = $end !== 0 ? $end : time();
    $age     = $end - $start;
    $days    = floor($age  / 86400);
    $hours   = floor(($age / 3600) % 3600);
    $minutes = floor(($age / 60) % 60);
    $seconds = $age % 60;
    $ageStr  = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
    return $ageStr;
}

function getMemUsage($peak = false): string
{
    $memUsage = $peak ? memory_get_peak_usage(true) : memory_get_usage(true);
    if ($memUsage === 0) {
        $memUsage = '_UNAVAILABLE_';
    } elseif ($memUsage < 1024) {
        $memUsage .= ' bytes';
    } elseif ($memUsage < 1048576) {
        $memUsage = round($memUsage / 1024, 2) . ' kilobytes';
    } else {
        $memUsage = round($memUsage / 1048576, 2) . ' megabytes';
    }
    return $memUsage;
}

function getSessionSize(string $sessionFile): string
{
    clearstatcache(true, $sessionFile);
    $size = filesize($sessionFile);
    if ($size === false) {
        $sessionSize = '_UNAVAILABLE_';
    } elseif ($size < 1024) {
        $sessionSize = $size . ' B';
    } elseif ($size < 1048576) {
        $sessionSize = round($size / 1024, 0) . ' KB';
    } else {
        $sessionSize = round($size / 1048576, 0) . ' MB';
    }
    return $sessionSize;
}

function getCpuUsage(): string
{
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        return $load[0] . '%';
    } else {
        return '_UNAVAILABLE_';
    }
}

function hostName(bool $full = false): string
{
    $name = getHostname();
    if (!$full && $name && strpos($name, '.') !== false) {
        $name = substr($name, 0, strpos($name, '.'));
    }
    return $name;
}

function strStartsWith($haystack, $needle, $caseSensitive = true)
{
    $length = strlen($needle);
    $startOfHaystack = substr($haystack, 0, $length);
    if ($caseSensitive) {
        if ($startOfHaystack === $needle) {
            return true;
        }
    } else {
        if (strcasecmp($startOfHaystack, $needle) == 0) {
            return true;
        }
    }
    return false;
}

function secondsToNexMinute(): int
{
    $now   = nowMilli();  // hrtime()[0]; // time();
    $next  = $now - ($now % 60000) + 60000;
    $diff  = $next - $now;
    $delay = intdiv($diff + 20, 1000);
    return $delay; // in sec
}

function parseCommand(string $msg, string $prefixes = '!/', int $maxParams = 3): array
{
    $command = ['prefix' => '', 'verb' => '', 'params' => []];
    $msg = trim($msg);
    if ($msg && strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
        $verb = strtolower(substr(rtrim($msg), 1, strpos($msg . ' ', ' ') - 1));
        if (ctype_alnum($verb)) {
            $command['prefix'] = $msg[0];
            $command['verb']   = $verb;
            $tokens = explode(' ', $msg, $maxParams + 1);
            for ($i = 1; $i < count($tokens); $i++) {
                $command['params'][$i - 1] = trim($tokens[$i]);
            }
        }
    }
    return $command;
}

function sendAndDelete(EventHandler $mp, int $dest, string $text, int $delaysecs = 30, bool $delmsg = true): Generator
{
    $result = yield $mp->messages->sendMessage([
        'peer'    => $dest,
        'message' => $text
    ]);
    if ($delmsg) {
        $msgid = $result['updates'][1]['message']['id'];
        $mp->callFork((function () use ($mp, $msgid, $delaysecs) {
            try {
                yield $mp->sleep($delaysecs);
                yield $mp->messages->deleteMessages([
                    'revoke' => true,
                    'id'     => [$msgid]
                ]);
                yield $mp->logger('Robot\'s startup message is deleted.', Logger::ERROR);
            } catch (\Exception $e) {
                yield $mp->logger($e, Logger::ERROR);
            }
        })());
    }
}

function safeStartAndLoop(API $MadelineProto, GenericLoop $genLoop = null, int $maxRecycles = 10): void
{
    $recycleTimes = [];
    while (true) {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $genLoop) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler('\EventHandler');
                if ($genLoop !== null) {
                    $genLoop->start(); // Do NOT use yield.
                }
                // Synchronously wait for the update loop to exit normally.
                // The update loop exits either on ->stop or ->restart (which also calls ->stop).
                Tools::wait(yield from $MadelineProto->API->loop());
                yield $MadelineProto->logger("Update loop exited!");
            });
            sleep(5);
            break;
        } catch (\Throwable $e) {
            try {
                $MadelineProto->logger->logger((string) $e, Logger::FATAL_ERROR);
                // quit recycling if more than $maxRecycles happened within the last minutes.
                $now = time();
                foreach ($recycleTimes as $index => $restartTime) {
                    if ($restartTime > $now - 1 * 60) {
                        break;
                    }
                    unset($recycleTimes[$index]);
                }
                if (count($recycleTimes) > $maxRecycles) {
                    // quit for good
                    Shutdown::removeCallback('restarter');
                    Magic::shutdown(1);
                    break;
                }
                $recycleTimes[] = $now;
                $MadelineProto->report("Surfaced: $e");
            } catch (\Throwable $e) {
            }
        }
    };
}

function checkTooManyRestarts(EventHandler $eh): Generator
{
    $startups = [];
    if (yield exists('data/startups.txt')) {
        $startupsText = get('data/startups.txt');
        $startups = explode('\n', $startupsText);
    } else {
        // Create the file
    }
    $startupsCount0 = count($startups);

    $nowMilli = nowMilli();
    $aMinuteAgo = $nowMilli - 60 * 1000;
    foreach ($startups as $index => $startupstr) {
        $startup = intval($startupstr);
        if ($startup < $aMinuteAgo) {
            unset($startups[$index]);
        }
    }
    $startups[] = strval($nowMilli);
    $startupsText = implode('\n', $startups);
    yield put('data/startups.txt', $startupsText);
    $restartsCount = count($startups);
    yield $eh->logger("startups: {now:$nowMilli, count0:$startupsCount0, count1:$restartsCount}");
    return $restartsCount;
}

class EventHandler extends MadelineEventHandler
{
    private $startTime;
    private $stopTime;

    private $robotID;     // id of the account which registered this app.
    private $owner;       // id or username of the owner of the robot.
    private $admins;      // ids of the accounts which heave admin rights.
    private $reportPeers; // ids of the support people who will receive the errors.

    private $notifState = true; // true: Notify; false: Never notify.
    private $notifAge   = 30;   // 30 => Delete the notifications after 30 seconds;  0 => Never Delete.

    private $oldAge   = 2;

    public function __construct(?APIWrapper $API)
    {
        parent::__construct($API);

        $this->startTime = time();
        $this->stopTime  = 0;

        if (file_exists('data')) {
            if (!is_dir('data')) {
                throw new Exception('data folder already exists as a file');
            }
        } else {
            mkdir('data'/*, NEEDED_ACCESS_LEVEL*/);
        }
    }

    public function onStart(): \Generator
    {
        yield $this->logger("Event Handler instatiated at " . date('d H:i:s', $this->startTime) . "!", Logger::ERROR);
        yield $this->logger("Event Handler started at " . date('d H:i:s') . "!", Logger::ERROR);

        $robot = yield $this->getSelf();
        $this->robotID = $robot['id'];
        if (isset($robot['username'])) {
            $this->account = $robot['username'];
        } elseif (isset($robot['first_name'])) {
            $this->account = $robot['first_name'];
        } else {
            $this->account = strval($robot['id']);
        }

        $this->ownerID     = $this->robotID;
        $this->admins      = [$this->robotID];
        $this->reportPeers = [$this->robotID];

        $this->setReportPeers($this->reportPeers);

        $this->processCommands  = false;
        $this->updatesProcessed = 0;

        $maxRestart = 5;
        $eh = $this;
        $restartsCount = yield checkTooManyRestarts($eh);
        $nowstr   = date('d H:m:s', $this->startTime);
        if ($restartsCount > $maxRestart) {
            $text = 'More than ' . $maxRestart . ' times restarted within a minute. Permanently shutting down ....';
            yield $this->logger($text, Logger::ERROR);
            yield $this->messages->sendMessage([
                'peer'    => $this->robotID,
                'message' => $text
            ]);
            if (Shutdown::removeCallback('restarter')) {
                yield $this->logger('Self-Restarter disabled.', Logger::ERROR);
            }
            yield $this->logger(ROBOT_NAME . ' on ' . hostname() . ' is stopping at ' . $nowstr, Logger::ERROR);
            yield $this->stop();
            return;
        }
        $text = ROBOT_NAME . ' started at ' . $nowstr . ' on ' . hostName() . ' using ' . $this->account . ' account.';
        $notifState = $this->notifState();
        $notifAge   = $this->notifAge();
        $dest       = $this->robotID;
        //yield sendAndDelete($eh, $dest, $text, $notifState, $notifAge);
        if ($notifState) {
            $result = yield $eh->messages->sendMessage([
                'peer'    => $dest,
                'message' => $text
            ]);
            yield $eh->logger($text, Logger::ERROR);
            if ($notifAge > 0) {
                $msgid = $result['updates'][1]['message']['id'];
                $eh->callFork((function () use ($eh, $msgid, $notifAge) {
                    try {
                        yield $eh->sleep($notifAge);
                        yield $eh->messages->deleteMessages([
                            'revoke' => true,
                            'id'     => [$msgid]
                        ]);
                        yield $eh->logger('Robot\'s startup message is deleted.', Logger::ERROR);
                    } catch (\Exception $e) {
                        yield $eh->logger($e, Logger::ERROR);
                    }
                })());
            }
        }
    }

    public function getRobotID(): int
    {
        return $this->robotID;
    }

    public function getLoopState(): bool
    {
        $state = $this->__get('loop_state');
        return $state ?? false;
    }
    public function setLoopState(bool $loopState): void
    {
        $this->__set('loop_state', $loopState);
    }

    public function notifState(): bool
    {
        return $this->notifState;
    }
    public function notifAge(): int
    {
        return $this->notifAge;
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
            yield $this->echo("Empty message-text:<br>" . PHP_EOL);
            yield $this->echo(toJSON($update) . '<br>' . PHP_EOL);
            exit;
        }
        $msgType      = $update['_'];
        $msgOrig      = $update['message']['message'] ?? null;
        $msg          = $msgOrig ? strtolower($msgOrig) : null;
        $messageId    = $update['message']['id'] ?? 0;
        $fromId       = $update['message']['from_id'] ?? 0;
        $replyToId    = $update['message']['reply_to_msg_id'] ?? null;
        $isOutward    = $update['message']['out'] ?? false;
        $peerType     = $update['message']['to_id']['_'] ?? '';
        $peer         = $update['message']['to_id'] ?? null;
        $byRobot      = $fromId    === $this->robotID && $msg;
        $toRobot      = $peerType  === 'peerUser' && $peer['user_id'] === $this->robotID && $msg;
        $replyToRobot = $replyToId === $this->robotID && $msg;
        $this->updatesProcessed += 1;

        $command = parseCommand($msgOrig);
        $verb    = $command['verb'] ?? null;
        $params  = $command['params'];

        // Recognize and log old or new commands.
        if ($byRobot && $toRobot && $msgType === 'updateNewMessage') {
            $msgDate = $update['message']['date'];
            $moment  = time();
            $diff    = $moment - $msgDate;
            $new     = $diff <= $this->oldAge;
            if ($verb && $verb !== '') {
                $age = $new ? 'New' : 'Old';
                yield $this->logger("$age Command:{verb:'$verb', time:" . date('H:m:s', $msgDate) . ", now:" . date('H:m:s', $moment) . ", age:$diff}", Logger::ERROR);
            }
        }

        // Start the Command Processing Engine
        if ($byRobot && $toRobot && $msgType === 'updateNewMessage' && !$this->processCommands && strStartsWith($msgOrig, ROBOT_NAME . ' started at ')) {
            $diff = time() - $update['message']['date'];
            if ($diff <= $this->oldAge) {
                $this->processCommands = true;
                yield $this->logger('Command-Processing engine started at ' . date('H:m:s', $moment), Logger::ERROR);
            }
        }

        if ($byRobot && $toRobot && $verb !== '' && $this->processCommands && $msgType === 'updateNewMessage') {
            switch ($verb) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'parse_mode' => 'HTML',
                        'message'    => '' .
                            '<b>Robot Instructions:</b><br>' .
                            '<br>' .
                            '>> <b>/help</b><br>' .
                            '   To print the robot commands<br>' .
                            ">> <b>/loop</b> on/off/state<br>" .
                            "   To query/change state of task repeater.<br>" .
                            '>> <b>/status</b><br>' .
                            '   To query the status of the robot.<br>' .
                            '>> <b>/stats</b><br>' .
                            '   To query the statistics of the robot.<br>' .
                            '>> <b>/crash</b><br>' .
                            '   To generate an exception for testing.<br>' .
                            '>> <b>/restart</b><br>' .
                            '   To restart the robot.<br>' .
                            '>> <b>/stop</b><br>' .
                            '   To stop the script.<br>' .
                            '>> <b>/logout</b><br>' .
                            '   To terminate the robot\'s session.<br>' .
                            '<br>' .
                            '<b>**Valid prefixes are / and !</b><br>',
                    ]);
                    break;
                case 'status':
                    $notif = 'OFF';
                    if ($this->notifState()) {
                        $notif = $this->notifAge() === 0 ? 'ON / Never wipe' : 'ON / Wipe in ' . $this->notifAge() . ' seconds';
                    }
                    $stats  = ROBOT_NAME . ' STATUS on ' . hostname() . ':' . PHP_EOL;
                    $stats .= 'Account: '  . $this->account              . PHP_EOL;
                    $stats .= 'Uptime: '   . getUptime($this->startTime) . PHP_EOL;
                    $stats .= 'Peak Memory: ' . getMemUsage(true)        . PHP_EOL;
                    $stats .= 'CPU: '         . getCpuUsage()            . PHP_EOL;
                    $stats .= 'Session size: ' . getSessionSize(SESSION_FILE) . PHP_EOL;
                    $stats .= 'Time: ' . date_default_timezone_get() . ' ' . date("h:i:sa") . PHP_EOL;
                    $stats .= 'Updates: '  . $this->updatesProcessed . PHP_EOL;
                    $stats .= 'Loop State: ' . ($this->getLoopState() ? 'ON' : 'OFF') . PHP_EOL;
                    $stats .= 'Notification: ' . $notif . PHP_EOL;
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => $stats,
                    ]);
                    break;
                case 'stats':
                    if (false) {
                        $peerCounts      = [
                            'user' => 0, 'bot' => 0, 'chat' => 0, 'supergroup' => 0, 'channel' => 0,
                            'chatForbidden' => 0, 'channelForbidden' => 0
                        ];
                        $params['mp']        = $this;
                        $params['limit']     = 100;
                        $params['pause_min'] =   0;
                        $params['pause_max'] =   0;
                        yield enumeratePeers($params, function (array $base, array $extension) use (&$peerCounts) {
                            $peerCounts[$base['subtype']] += 1;
                        });
                        $stats  = 'STATISTICS'                     . PHP_EOL;
                        $stats  = ROBOT_NAME . ' STATISTICS on ' . hostname() . ':' . PHP_EOL;
                        $stats .= 'Users: '       . $peerCounts['user']         . PHP_EOL;
                        $stats .= 'Bots: '        . $peerCounts['bot']          . PHP_EOL;
                        $stats .= 'Chats: '       . $peerCounts['chat']         . PHP_EOL;
                        $stats .= 'Supergroups: ' . $peerCounts['supergroup']   . PHP_EOL;
                        $stats .= 'channels: '    . $peerCounts['channel']      . PHP_EOL;
                    } else {
                        $stats = 'To Be Implemented';
                    }
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => $stats,
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
                case 'crash':
                    yield $this->logger("Purposefully crashing the script....", Logger::ERROR);
                    throw new \Exception('Artificial exception generated for testing the robot.');
                case 'restart':
                    yield $this->logger('The robot re-started by the owner.', Logger::ERROR);
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Restarting the robot ...',
                    ]);
                    $date = $result['date'];
                    $this->restart();
                    break;
                case 'logout':
                    yield $this->logger('the robot is logged out by the owner.', Logger::ERROR);
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is logging out. ...',
                    ]);
                    $date = $result['date'];
                    $this->logout();
                case 'stop':
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Robot is stopping ...',
                    ]);
                    break;
                default:
                    $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Invalid command: ' . "'" . $verb . "'",
                    ]);
                    break;
            } // enf of the command switch
        } // end of the commander qualification check

        //Function: Finnish executing the Stop command.
        if ($byRobot && $msgOrig === 'Robot is stopping ...') {
            if (Shutdown::removeCallback('restarter')) {
                yield $this->logger('Self-Restarter disabled.', Logger::ERROR);
            }
            yield $this->stop();
        }
    } // end of function
} // end of the class

error_log("Trying to execute the script at " . date('d H:i:s') . "!");

$settings['logger']['logger_level'] = Logger::ERROR;
$settings['logger']['logger'] = Logger::FILE_LOGGER;
$settings['peer']['full_info_cache_time'] = 60;
$settings['serialization']['cleanup_before_serialization'] = true;
$settings['serialization']['serialization_interval'] = 60;
$settings['app_info']['app_version']    = ROBOT_NAME;
$settings['app_info']['system_version'] = 'WEB393';
$madelineProto = new API(SESSION_FILE, $settings);
$madelineProto->async(true);

$robotName = ROBOT_NAME;
$startTime = time();
$logger    = $madelineProto->logger;
$tempId    = Shutdown::addCallback(
    static function () use ($madelineProto, $robotName, $startTime) {
        $now      = time();
        $duration = $now - $startTime;
        $madelineProto->logger($robotName . " stopped at " . date("d H:i:s", $now) . "!  Execution duration:" . gmdate('H:i:s', $duration), Logger::ERROR);
    },
    'duration'
);

$genLoop = new GenericLoop(
    $madelineProto,
    function () use ($madelineProto) {
        $eventHandler = $madelineProto->getEventHandler();
        if ($eventHandler->getLoopState()) {
            $msg = 'Time is ' . date('H:i:s') . '!';
            yield $madelineProto->logger($msg, Logger::ERROR);
            if (false) {
                yield $madelineProto->account->updateProfile([
                    'about' => date('H:i:s')
                ]);
            }
            if (false) {
                $robotID = $eventHandler->getRobotID();
                yield $madelineProto->messages->sendMessage([
                    'peer'    => $robotID,
                    'message' => $msg
                ]);
            }
        }
        $delay = yield secondsToNexMinute($madelineProto);
        return 60; // Repeat exactly at the begining of the next minute.
    },
    'Repeating Loop'
);

$maxRecycles = 5;
safeStartAndLoop($madelineProto, $genLoop, $maxRecycles);

exit('Finished');
