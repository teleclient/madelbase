<?php

declare(strict_types = 1);
ini_set('memory_limit','256MB');

function errHandle($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    if ($errNo == E_NOTICE || $errNo == E_WARNING) {
        throw new ErrorException($msg, $errNo);
    } else {
        echo $msg;
    }
}
//set_error_handler('errHandle');

function toJSON($var, $pretty = true) {
    $opts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
    return json_encode($var, !$pretty? $opts : $opts|JSON_PRETTY_PRINT);
}

function getVerb(array $update, string $prefixes = '!/'): array {
    $verb = '';
    $rest = '';
    $msg = $update['message']['message']?? '';
    if(strlen($msg) > 1) {
        if(strpos($prefixes, $msg[0]) !== false) {
            $spcloc = strpos($msg, ' ');
            if($spcloc === false) {
                $verb = strtolower(trim(substr($msg, 1)));
            } else {
                $verb = strtolower(substr($msg, 1, $spcloc-1));
                $rest = substr($msg, $spcloc+1);
            }
        }
    }
    return compact('verb', 'rest');
}

function peerDetail($update): array {
    $chattype = '';
    $botapiid = 0;
    if ($update['message']['_'] === 'message') {
        $peer = $update['message']['to_id'];
        switch($peer['_']) {
            case 'peerUser':
                $botapiid = $peer['user_id'];
                if(isset($update['message']['post'])) {
                    $chattype = 'user';
                } else {
                    $chattype = 'bot';
                }
                break;
            case 'peerChat':
                $chattype = 'basicgroup';
                $botapiid = -1 * $peer['chat_id'];
                break;
            case 'peerChannel':
                $botapiid = intval('-100' . strval($peer['channel_id']));
                if($update['message']['post']) {
                    $chattype = 'supergroup';
                } else {
                    $chattype = 'channel';
                }
                break;
        }
    }
    return compact('chattype', 'botapiid');
}

function fields(array $update, string $prefixes = '/!', bool $echo = false): array {
    $peer     = $update['message']['to_id']  ?? null;
    $fromid   = $update['message']['from_id']?? 0;
    $msgid    = $update['message']['id']     ?? 0;
    $msg      = $update['message']['message']?? '';
    extract(peerDetail($update));
    extract(getVerb($update, $prefixes));
    if($echo) {
        $msgFront = substr(str_replace(array("\r", "\n"), '<br>', $msg), 0, 50);
        echo('{' .
            (isset($update['message'])? '_:' . $update['message']['_'] . ', ' : '') .
            ($fromid !== 0? 'from:'. $fromid .', ' : '') .
            'to:' . $chattype .'#'. $botapiid . ', ' .
            ($verb !== ''  ? 'verb:\'' . $verb . '\', ' : '') .
            'msg:"'. $msgFront .'"}' .PHP_EOL
        );
    }
    return compact('peer', 'fromid', 'chattype', 'botapiid', 'msgid', 'verb', 'rest', 'msg');
}

if (\file_exists('vendor/autoload.php')) {
    include 'vendor/autoload.php';
} else {
    if (!\file_exists('madeline.php')) {
        \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
    }
    /**
     * @psalm-suppress MissingFile
     */
    include 'madeline.php';
}

use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use danog\MadelineProto\Tools;
use danog\MadelineProto\Logger;
use danog\MadelineProto\RPCErrorException;

/**
 * Event handler class.
 */
class MyEventHandler extends EventHandler
{
    const ADMIN = "webwarp"; // Change this

    private array $robot;
    private int   $robotID;
    private int   $start;

    private $replyMsg;
    private $badMsg;
    private $sendMsg;

    private array $fields;

    public function getReportPeers()
    {
        return [self::ADMIN];
    }

    public function __sleep(): array
    {
        return [];
    }

    public function onStart()
    {
        $this->robot    = yield $this->getSelf();
        $this->robotID  = $this->robot['id'];
        $this->start    = time();

        $this->badMsg = function (): \Generator {
            $verb   = $this->fields['verb'];
            $fromid = $this->fields['fromid'];
            $peer   = $this->fields['peer'];
            $msgid  = $this->fields['msgid'];

            $dest = $peer['_'] === 'peerUser' && $peer['user_id'] === $fromid ? $peer : $fromid;
            $dest = $peer['_'] !== 'peerUser' ? $peer : $dest;
            yield $this->messages->sendMessage([
                'peer'            => $dest,
                'reply_to_msg_id' => $msgid,
                'message'         => 'Invalid ' . $verb . ' arguments',
                'parse_mode'      => 'html'
            ]);
        };

        $this->replyMsg = function (string $text): \Generator {
            $fromid = $this->fields['fromid'];
            $peer   = $this->fields['peer'];
            $msgid  = $this->fields['msgid'];

            $dest = $peer['_'] === 'peerUser' && $peer['user_id'] === $fromid ? $peer : $fromid;
            $dest = $peer['_'] !== 'peerUser' ? $peer : $dest;
            return yield $this->messages->sendMessage([
                'peer'            => $dest,
                'reply_to_msg_id' => $msgid,
                'message'         => $text,
                'parse_mode'      => 'html'
            ]);
        };

        $this->sendMsg = function (string $text): \Generator {
            $fromid = $this->fields['fromid'];
            $peer   = $this->fields['peer'];

            $dest = $peer['_'] === 'peerUser' && $peer['user_id'] === $fromid ? $peer : $fromid;
            $dest = $peer['_'] !== 'peerUser' ? $peer : $dest;
            return yield $this->messages->sendMessage([
                'peer'       => $dest,
                'message'    => $text,
                'parse_mode' => 'html'
            ]);
        };
    }

    public function onUpdateNewChannelMessage(array $update): \Generator
    {
        return $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage(array $update): \Generator
    {
        try {
            $this->fields = fields($update, '/!', true);
            extract($this->fields);

            switch ($verb) {
                case 'ping':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'message' => '✅I\'m online',
                        'id'      => $msgid
                    ]);
                    break;
                case 'mem':
                    $memUsage = memory_get_peak_usage(true);
                    yield $this->messages->sendMessage([
                        'peer'       => $peer,
                        'message'    => '<strong>' . round($memUsage / 1024) . 'KB</strong>',
                        'id'         => $msgid,
                        'parse_mode' => 'HTML'
                    ]);
                    break;
                case 'crash':
                    if (true/*$cmd->paramNone()*/) {
                        yield ($this->replyMsg)("Robot is crashing!");
                        throw new Exception('Artificial test exception.');
                    } else {
                        yield ($this->badMsg)();
                    }
                    break;
                case 'logout':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $msgId,
                        'message' => 'The robot is logging out. ...',
                    ]);
                    $this->logger('the robot is logged out by the owner.');
                    $this->logout();
                    // continue to stop
                case 'stop':
                        if (true/*$cmd->paramNone()*/) {
                            yield ($this->replyMsg)('Robot is stopping ...');
                        } else {
                            yield ($this->badMsg)();
                        }
                        break;
                case 'restart':
                    if (true/*$cmd->paramNone()*/) {
                        yield ($this->replyMsg)("Robot restarted!");
                        if (false) {
                            yield $this->messages->deleteHistory([
                                'just_clear' => true,
                                'revoke'     => true,
                                'peer'       => $peer,
                                'max_id'     => $msgid
                            ]);
                        }
                        yield $this->restart();
                    } else {
                        yield ($this->badMsg)();
                    }
                    break;
            }

            if ($fromid === $this->robotID) {
                if ($msg === 'Robot is stopping ...') {
                    $this->stop();
                }
            }
        } catch (RPCErrorException $e) {
            $this->report("Surfaced: $e");
            throw new Exception();
        } catch (Exception $e) {
            if (\stripos($e->getMessage(), 'invalid constructor given') === false) {
                $this->report("Surfaced: $e");
            }
            //throw new Exception();
        }
    }
}

if(file_exists('MadelineProto.log')) unlink('MadelineProto.log');
$settings['logger']['logger_level']         = Logger::ULTRA_VERBOSE;
$settings['logger']['logger']               = Logger::FILE_LOGGER;
$settings['db']['type']                     = 'mysql';
$settings['db']['mysql']['host']            = '127.0.0.1';
$settings['db']['mysql']['port']            = 3306;
$settings['db']['mysql']['user']            = 'root';
$settings['db']['mysql']['password']        = 'minkie';
$settings['db']['mysql']['database']        = 'MadelineProto';
$settings['db']['mysql']['max_connections'] = 10;
$settings['db']['mysql']['idle_timeout']    = 60;
$settings['db']['mysql']['cache_ttl']       = '+5 minutes';

$MadelineProto = new API('bot.madeline', $settings??[]);
$MadelineProto->async(true);

$MadelineProto->loop(function () use ($MadelineProto) {
    $owner = yield $MadelineProto->start();
    yield $MadelineProto->setEventHandler(MyEventHandler::class);
    //yield $MadelineProto->getEventHandler('\EventHandler')->setOwner($owner);
});

$MadelineProto->loop();

exit('Robot Stopped!');