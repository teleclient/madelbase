<?php

if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
	public function onUpdateNewChannelMessage(array $update): Generator
	{
		yield $this->onUpdateNewMessage($update);
	}

	public function onUpdateNewMessage(array $update): Generator
	{
        if ($update['message']['_'] === 'messageEmpty') {
            return;
        }

        [
            $msg_id,
            $text,
            $from_id,
            $reply_id
        ] = [
            $update['message']['id']              ?? false,
            $update['message']['message']         ?? '',
            $update['message']['from_id']         ?? false,
            $update['message']['reply_to_msg_id'] ?? false
        ];
        
        // Codes ...
        
	}
}

$MadelineProto = new \danog\MadelineProto\API('session.madeline');
$MadelineProto->async(true);
$MadelineProto->loop(function () use ($MadelineProto) {
	yield $MadelineProto->start();
	yield $MadelineProto->setEventHandler(EventHandler::class);
});
$MadelineProto->loop();
