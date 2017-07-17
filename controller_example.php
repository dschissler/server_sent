<?php

$server = $this->getDI()
    ->getServerSent();

$server
    ->subscribe($instanceUuid, function($data) {
        $action = $data['action'];
        $channel = $data['channel'];

        switch ($action) {
            case 'subscribe':
                $this->subscribe($channel, function($data) {
                    $userId = $this->getDI()
                        ->getAuth()
                        ->getId();

                    if ($userId !== $data['user']['id']) {
                        $this->sendEvent([
                            'name' => 'instance',
                            'data' => $data,
                        ]);
                    }
                });
                break;
            case 'unsubscribe':
                $this->unsubscribe($channel);
                break;
        }
    })
    ->start();
