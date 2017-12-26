<?php

abstract class Worker
{

    protected $pid;
    protected $stop = false;

    protected $_server;
    protected $_service;
    protected $_master;

    protected $services = array();
    protected $clients = array();
    protected $handshakes = array();
    protected $request = array();

    protected $ips = array();

    public function __construct($server, $service, $master)
    {
        $this->_server = $server;
        $this->_service = $service;
        $this->_master = $master;

        $this->pid = posix_getpid();
    }

    public function stop()
    {
        Log::Debug("Stop worker (PID {$this->pid})");

        $this->stop = true;
    }

    public function start()
    {
        Log::Debug("Start worker (PID {$this->pid})");

        while ($this->stop == false) {

            $read = $this->clients + $this->services;

            if (isset($this->_server)) {
                $read[] = $this->_server;
            }

            if (isset($this->_service)) {
                $read[] = $this->_service;
            }

            if (isset($this->_master)) {
                $read[] = $this->_master;
            }

            if (!$read) {
                sleep(1);
                continue;
            }

            $write = array();

            foreach ($this->clients as $connectionId => $connectionInfo) {
                if (!isset($this->handshakes[$connectionId])) {
                    $write[] = $this->clients[$connectionId];
                }
            }

            $except = $read;

            stream_select($read, $write, $except, null);

            foreach ($read as $connection) {

                if ($connection == $this->_server) {
                    if ($connection = @stream_socket_accept($this->_server, 0)) {
                        stream_set_blocking($connection, false);
                        $this->clients[intval($connection)] = $connection;
                    }
                } elseif ($connection == $this->_service) {
                    if ($connection = @stream_socket_accept($this->_service, 0)) {
                        stream_set_blocking($connection, 0);
                        $this->services[intval($connection)] = $connection;
                    }
                } else {

                    if (in_array($connection, $this->services)) {
                        $data = fread($connection, 1024);
                        if (!strlen($data)) {
                            unset($this->services[intval($connection)]);
                            $this->onClose($connection);
                            @fclose($connection);
                            continue;
                        }
                        $this->onSend($data);
                        unset($this->services[intval($connection)]);
                    } else {

                        if (!isset($this->handshakes[intval($connection)])) {
                            if (!$this->handshake($connection)) {
                                unset($this->clients[intval($connection)]);
                                unset($this->handshakes[intval($connection)]);
                                $this->onClose($connection);
                                @fclose($connection);
                                continue;
                            }
                        } else {
                            Log::Debug("New DATA");

                            $data = fread($connection, 1024);
                            if (!strlen($data)) {
                                unset($this->clients[intval($connection)]);
                                unset($this->handshakes[intval($connection)]);
                                $this->onClose($connection);
                                @fclose($connection);
                                continue;
                            }

                            $this->onMessage($connection, $data);
                        }

                    }
                }

            }

            foreach ($write as $connection) {
                if (!isset($this->handshakes[intval($connection)])) {
                    continue;
                }
                $this->onOpen($connection);

                if (empty($this->request[intval($connection)])) {
                    continue;
                }
                $this->onRequest($connection, $this->request[intval($connection)]);
            }

            foreach ($except as $connection) {
                $this->onError($connection);
            }

            pcntl_signal_dispatch();

            sleep(1);

        }
    }

    protected function handshake($connection)
    {
        if (isset($this->handshakes[intval($connection)])) {
            return true;
        }

        Log::Debug("New connection from $connection");

        $headers = fread($connection, 10000);
        preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $match);

        if (empty($match[1])) {
            return false;
        }

        $info = $match[1];

        $this->handshakes[intval($connection)] = $info;
        $this->request[intval($connection)] = $this->parseQuery($headers);

        $SecWebSocketAccept = base64_encode(pack('H*', sha1($info . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: {$SecWebSocketAccept}\r\n\r\n";

        fwrite($connection, $upgrade);

        return true;
    }

    protected function parseQuery($headers)
    {
        preg_match('/^GET\s(.+)\sHTTP\/1.1\r\n/', $headers, $match);
        $request = parse_url($match[1], PHP_URL_QUERY);
        parse_str($request, $params);

        return $params;
    }

    protected function encode($payload, $type = 'text')
    {
        $frameHead = array();
        $payloadLength = strlen($payload);

        switch ($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        if ($payloadLength > 65535) {
            $ext = pack('NN', 0, $payloadLength);
            $secondByte = 127;
        } elseif ($payloadLength > 125) {
            $ext = pack('n', $payloadLength);
            $secondByte = 126;
        } else {
            $ext = '';
            $secondByte = $payloadLength;
        }

        return $data  = chr($frameHead[0]) . chr($secondByte) . $ext . $payload;
    }

    protected function decode($data)
    {
        if (strlen($data) < 2) return false;

        $unmaskedPayload = '';
        $decodedData = array();

        // estimate frame type:
        $firstByteBinary = sprintf('%08b', ord($data[0]));
        $secondByteBinary = sprintf('%08b', ord($data[1]));
        $opcode = bindec(substr($firstByteBinary, 4, 4));
        $isMasked = ($secondByteBinary[0] == '1') ? true : false;
        $payloadLength = ord($data[1]) & 127;

        switch ($opcode) {
            // text frame:
            case 1:
                $decodedData['type'] = 'text';
                break;
            case 2:
                $decodedData['type'] = 'binary';
                break;
            // connection close frame:
            case 8:
                $decodedData['type'] = 'close';
                break;
            // ping frame:
            case 9:
                $decodedData['type'] = 'ping';
                break;
            // pong frame:
            case 10:
                $decodedData['type'] = 'pong';
                break;
            default:
                $decodedData['type'] = '';
        }

        if ($payloadLength === 126) {
            if (strlen($data) < 4) return false;
            $payloadOffset = 8;
            $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
        } elseif ($payloadLength === 127) {
            if (strlen($data) < 10) return false;
            $payloadOffset = 14;
            for ($tmp = '', $i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($data[$i + 2]));
            }
            $dataLength = bindec($tmp) + $payloadOffset;
        } else {
            $payloadOffset = 6;
            $dataLength = $payloadLength + $payloadOffset;
        }

        if (strlen($data) < $dataLength) {
            return false;
        }

        if ($isMasked) {
            if ($payloadLength === 126) {
                $mask = substr($data, 4, 4);
            } elseif ($payloadLength === 127) {
                $mask = substr($data, 10, 4);
            } else {
                $mask = substr($data, 2, 4);
            }

            for ($i = $payloadOffset; $i < $dataLength; $i++) {
                $j = $i - $payloadOffset;
                if (isset($data[$i])) {
                    $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                }
            }
            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset = $payloadOffset - 4;
            $decodedData['payload'] = substr($data, $payloadOffset, $dataLength - $payloadOffset);
        }

        return $decodedData;
    }

    protected function onError($connection)
    {
        Log::Debug("Error: $connection");
    }

    abstract protected function onOpen($connection);

    abstract protected function onClose($connection);

    abstract protected function onRequest($connection, $params);

    abstract protected function onMessage($connection, $data);

    abstract protected function onSend($data);

}