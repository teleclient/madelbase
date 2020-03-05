<?php  declare(strict_types=1);
require_once 'madeline.php';
require_once   'config.php';

$settings['app_info']['api_id']     = $GLOBALS['API_ID'];
$settings['app_info']['api_hash']   = $GLOBALS['API_HASH'];
$MadelineProto = new \danog\MadelineProto\API('login.madeline', $settings);
$MadelineProto->async(true);

$MadelineProto->loop(function () use ($MadelineProto, $settings) {
    $wrapper = new \danog\MadelineProto\MyTelegramOrgWrapper($settings);
    $wrapper->async(true);
    yield $wrapper->login($GLOBALS['ACCOUNT_PHONE']);
    yield $wrapper->completeLogin(yield $wrapper->readline('Enter the code'));
});