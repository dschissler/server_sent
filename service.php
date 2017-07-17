<?php

use Webird\Http\ServerSent;

/**
 *
 */
$di->setShared('serverSent', function() {
    $server = new ServerSent([
        'keepAlive'  => 2,
        'retryDelay' => 2,
    ]);
    $server->setDI($this);

    return $server;
});
