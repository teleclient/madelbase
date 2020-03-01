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
    //yield $MadelineProto->start();
    //yield $MadelineProto->setEventHandler('\EventHandler');

    $wrapper = new \danog\MadelineProto\MyTelegramOrgWrapper($settings);
    $wrapper->async(true);

    yield $wrapper->login($GLOBALS['ACCOUNT_PHONE']);

    yield $wrapper->completeLogin(yield $wrapper->readline('Enter the code'));

    if (yield $wrapper->loggedIn()) {
        if (yield $wrapper->hasApp()) {
            $app = yield $wrapper->getApp();
        } else {
            $app_title = yield $wrapper->readLine('Enter the app\'s name, can be anything: ');
            $short_name = yield $wrapper->readLine('Enter the app\'s short name, can be anything: ');
            $url = yield $wrapper->readLine('Enter the app/website\'s URL, or t.me/yourusername: ');
            $description = yield $wrapper->readLine('Describe your app: ');

            $app = yield $wrapper->createApp([
                'app_title'     => $app_title,
                'app_shortname' => $short_name,
                'app_url'       => $url,
                'app_platform'  => 'web',
                'app_desc'      => $description
            ]);
        }

        \danog\MadelineProto\Logger::log($app);
    }
});