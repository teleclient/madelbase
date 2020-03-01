<?php  declare(strict_types=1);

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require_once 'madeline.php';
require_once   'config.php';

if (file_exists('MadelineProto.log')) {unlink('MadelineProto.log');}
$settings['logger']['logger_level'] = \danog\MadelineProto\Logger::ULTRA_VERBOSE;
$settings['logger']['logger']       = \danog\MadelineProto\Logger::FILE_LOGGER;
$settings['app_info']['api_id']     = $GLOBALS['API_ID'];
$settings['app_info']['api_hash']   = $GLOBALS['API_HASH'];

$MadelineProto = new \danog\MadelineProto\API('login.madeline', $settings);
$MadelineProto->async(true);

$MadelineProto->loop(function () use ($MadelineProto, $settings) {
    $wrapper = new \danog\MadelineProto\MyTelegramOrgWrapper($settings);
    $wrapper->async(true);
    yield $wrapper->login($GLOBALS['ACCOUNT_PHONE']);
    yield $wrapper->completeLogin(yield $wrapper->readline('Enter the code'));
    if (yield $wrapper->loggedIn()) {
        throw new Exception('Unsuccessful login!');
    }
});