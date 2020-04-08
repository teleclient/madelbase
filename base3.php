<?php
set_include_path(get_include_path().':'.realpath(dirname(__FILE__).'/MadelineProto/'));

if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
define('MADELINE_BRANCH', 'deprecated');
include 'madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    public function onAny($update)
    {
        if ($update['message']['out']?? null) {
            return;
        }

        $res = json_encode($update, JSON_PRETTY_PRINT);
        if ($res == '') {
            $res = var_export($update, true);
        }

        try {
            $this->messages->sendMessage([
                'peer'            => $update,
                'message'         => $res,
                'reply_to_msg_id' => $update['message']['id']?? null,
                'entities' => [[
                    '_'        => 'messageEntityPre',
                    'offset'   => 0,
                    'length'   => strlen($res),
                    'language' => 'json'
                ]]
            ]);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            \danog\MadelineProto\Logger::log((string) $e, \danog\MadelineProto\Logger::FATAL_ERROR);
        } catch (\danog\MadelineProto\Exception $e) {
            \danog\MadelineProto\Logger::log((string) $e, \danog\MadelineProto\Logger::FATAL_ERROR);
            //$this->messages->sendMessage([
            //    'peer' => '@danogentili',
            //    'message' => $e->getCode().': '.$e->getMessage().PHP_EOL.$e->getTraceAsString()
            //]);
        }
    }
}

$MadelineProto = new \danog\MadelineProto\API('bot.madeline');

$MadelineProto->start();
$MadelineProto->setEventHandler('\EventHandler');
$MadelineProto->loop();