<?php

function toJSON($var, bool $oneLine = false): string {
    $opts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | (!$oneLine? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '')? $json : var_export($var, true);
    return $json;
}

function parseMsg($update) {
    $msg = $update['message']['message']?? null;
    if($msg) {
        $msg = trim($msg);
        if(strlen($msg) > 1 && in_array(substr($msg, 0, 1), ['!', '@', '/'])) {
            $tokens = explode(' ', trim($msg));
            $command['verb']  = substr($tokens[0], 1);
            $command['param'] = [];
            for($i = 1; $i < count($tokens); $i++) {
                $command['param'][$i - 1] = trim($tokens[$i]);
            }
            return $command;
        }
    }
    return $msg;
}

var_export(parseMsg('!ping xxx yyy'));
