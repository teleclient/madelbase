<?php

const Token = '';

function Request(string $method, array $data = [])
{
	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL            => 'https://api.telegram.org/bot' . Token . '/' . $method,
		CURLOPT_POSTFIELDS     => $data,
		CURLOPT_RETURNTRANSFER => true
	]);
	$result = curl_exec($curl);
    
	if (curl_errno($curl)) {
		error_log(curl_error($curl).PHP_EOL);
        return false;
	}
    
	curl_close($curl);
	return json_decode($result);
}

$offset = -1;

while (true) {
	foreach (Request('getUpdates', ['offset' => $offset, 'limit' => 20])->result as $update) {
		$offset = $update->update_id + 1;
        
        if (isset($update->message)) {
            echo json_encode($update->message, 64 | 128 | 256) . "\n\n";
        }
	}
}
