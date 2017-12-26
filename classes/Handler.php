<?php

class Handler extends Worker
{

    protected function onOpen($connection)
    {
        Log::Notice("Connection '$connection' opened");
    }

    protected function onClose($connection)
    {
        Log::Notice("Connection '$connection' closed");
    }

    protected function onRequest($connection, $params)
    {
        Log::Notice("New Request");
    }

    protected function onMessage($connection, $data)
    {
        Log::Notice("New Message Received");

        $data = $this->decode($data);

        if (!$data['payload']) {
            return;
        }

        if (!mb_check_encoding($data['payload'], 'utf-8')) {
            return;
        }

        $message = strip_tags($data['payload']);

        $this->sendToClient($message, $connection);
    }

    protected function onSend($data)
    {
        Log::Notice("New Message Sent");

        $this->sendToAll($data);
    }

    // ----------------------------

    protected function sendToClient($message, $connection)
    {
        $data = $this->encode($message);

        @fwrite($connection, $data);
    }

    protected function sendToAll($message)
    {
        $data = $this->encode($message);

        if (!empty($this->clients)) {
            $write = $this->clients;
            if (stream_select($read, $write, $except, 0)) {
                foreach ($write as $connection) {
                    @fwrite($connection, $data);
                }
            }
        }
    }

}