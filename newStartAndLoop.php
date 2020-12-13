<?php

use danog\MadelineProto\API;
use danog\MadelineProto\Ipc\Logger;
use danog\MadelineProto\Ipc\Tools;
use danog\MadelineProto\Ipc\SettingsEmpty;
use danog\MadelineProto\Ipc\Client;

/**
 * Start MadelineProto and the event handler (enables async).
 *
 * Also initializes error reporting, catching and reporting all errors surfacing from the event loop.
 *
 * @param string $eventHandler Event handler class name
 *
 * @return void
 */
/*public*/ function startAndLoop(API $MadelineProto, string $eventHandler): void
{
    while (true) {
        try {
            Tools::wait($MadelineProto->startAndLoopAsync($eventHandler));
            return;
        } catch (\Throwable $e) {
            $MadelineProto->logger->logger((string) $e, Logger::FATAL_ERROR);
            $MadelineProto->report("Surfaced: $e");
        }
    }
}

/**
 * Start multiple instances of MadelineProto and the event handlers (enables async).
 *
 * @param API[]           $instances    Instances of madeline
 * @param string[]|string $eventHandler Event handler(s)
 *
 * @return void
 */
/*public static*/ function startAndLoopMulti(array $instances, $eventHandler): void
{
    if (\is_string($eventHandler)) {
        $eventHandler = \array_fill_keys(\array_keys($instances), $eventHandler);
    }

    $instanceOne = \array_values($instances)[0];
    while (true) {
        try {
            $promises = [];
            foreach ($instances as $k => $instance) {
                $instance->start(['async' => false]);
                $promises[] = $instance->startAndLoopAsync($eventHandler[$k]);
            }
            Tools::wait(Tools::all($promises));
            return;
        } catch (\Throwable $e) {
            $instanceOne->logger((string) $e, Logger::FATAL_ERROR);
            $instanceOne->report("Surfaced: $e");
        }
    }
}

/**
 * Start MadelineProto and the event handler (enables async).
 *
 * Also initializes error reporting, catching and reporting all errors surfacing from the event loop.
 *
 * @param string $eventHandler Event handler class name
 *
 * @return \Generator
 */
/*public*/ function startAndLoopAsync(API $MadelineProto, string $eventHandler): \Generator
{
    $errors = [];
    $MadelineProto->async(true);

    if ($MadelineProto->API instanceof Client) {
        yield $MadelineProto->API->stopIpcServer();
        yield $MadelineProto->API->disconnect();
        yield from $MadelineProto->connectToMadelineProto(new SettingsEmpty, true);
    }

    $started = false;
    while (true) {
        try {
            yield $MadelineProto->start();
            yield $MadelineProto->setEventHandler($eventHandler);
            $started = true;
            return yield from $MadelineProto->API->loop();
        } catch (\Throwable $e) {
            $errors = [\time() => $errors[\time()] ?? 0];
            $errors[\time()]++;
            if ($errors[\time()] > 10 && (!$MadelineProto->inited() || !$started)) {
                $MadelineProto->logger->logger("More than 10 errors in a second and not inited, exiting!", Logger::FATAL_ERROR);
                return;
            }
            echo $e;
            $MadelineProto->logger->logger((string) $e, Logger::FATAL_ERROR);
            $MadelineProto->report("Surfaced: $e");
        }
    }
}
