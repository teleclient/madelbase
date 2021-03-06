<?php declare(strict_types=1);
date_default_timezone_set('Asia/Tehran');

if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
include 'madeline.php';

use danog\MadelineProto\EventHandler;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\RPCErrorException;


class MyEventHandler extends EventHandler
{
    // @var int|string Username or ID of bot admin
    const ADMIN = "webwarp"; // Change this

    // Get peer(s) where to report errors  @return int|string|array
    public function getReportPeers()
    {
    return [/*self::ADMIN*/];
    }

    // Called on startup, can contain async calls for initialization of the bot
    public function onStart(): \Generator
    {
        try {
            yield $this->echo('Hello from the EventHandler.'.PHP_EOL);
            //yield $this->start();
            //yield $this->setEventHandler($eventHandler);
            //$loop = yield from $this->API->loop();
            //return $loop;
        } catch (\Throwable $e) {
            $this->logger->logger((string) $e, Logger::FATAL_ERROR);
            $this->report("Surfaced: $e");
        }
    }

    public function toJSON($var, bool $pretty = true): string {
        $opts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
        $json = \json_encode($var, $opts | ($pretty? JSON_PRETTY_PRINT : 0));
        $json = ($json !== '')? $json : var_export($var, true);
        return $json;
    }

    function parseMsg($msg) {
        $command = ['verb' => '', 'pref' => '', 'count' => 0, 'params' => []];
        if($msg) {
            $msg    = ltrim($msg);
            $prefix = substr($msg, 0, 1);
            if(strlen($msg) > 1 && in_array($prefix, ['!', '@', '/'])) {
                $space = strpos($msg, ' ')?? 0;
                $verb = strtolower(substr(rtrim($msg), 1, ($space === 0? strlen($msg) : $space) - 1));
                $verb = strtolower($verb);
                if(ctype_alnum($verb)) {
                    $command['pref']  = $prefix;
                    $command['verb']  = $verb;
                    $tokens = explode(' ', trim($msg));
                    $command['count'] = count($tokens) - 1;
                    for($i = 1; $i < count($tokens); $i++) {
                        $command['params'][$i - 1] = trim($tokens[$i]);
                    }
                }
                return $command;
            }
        }
        return $command;
    }

    // Handle updates from supergroups and channels
    public function onUpdateNewChannelMessage(array $update): \Generator
    {
        return $this->onUpdateNewMessage($update);
    }

    // Handle updates from users.
    public function onUpdateNewMessage(array $update): \Generator
    {
        if ($update['message']['_'] === 'messageEmpty' /*|| $update['message']['out']?? false*/) {
            return;
        }
        $res = $this->toJSON($update, false);
        yield $this->logger($res);

        try {
            // WARNING: setting to true spams the groups you are a member of.
            /*
            yield $this->messages->sendMessage([
                'peer'            => $update,
                'message'         => "<code>$res</code>",
                'reply_to_msg_id' => $update['message']['id'] ?? null,
                'parse_mode'      => 'HTML'
            ]);
            */
            /*
            if (isset($update['message']['media']) &&
                    $update['message']['media']['_'] !== 'messageMediaGame')
            {
                yield $this->messages->sendMedia([
                    'peer'    => $update,
                    'message' => $update['message']['message'],
                    'media'   => $update
                ]);
            }
            */
        } catch (RPCErrorException $e) {
            $this->report("Surfaced: $e");
        } catch (Exception $e) {
            if (\stripos($e->getMessage(), 'invalid constructor given') === false) {
                $this->report("Surfaced: $e");
            }
        }
    }
}


if (file_exists('MadelineProto.log')) {unlink('MadelineProto.log');}
$settings['logger']['logger_level'] = \danog\MadelineProto\Logger::ULTRA_VERBOSE;
$settings['logger']['logger']       = \danog\MadelineProto\Logger::FILE_LOGGER;

$MadelineProto = new API('session.madeline', $settings);
$MadelineProto->startAndLoop(MyEventHandler::class);