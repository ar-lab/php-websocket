<?php

class Server
{

    const PIDFILE = "/var/run/websocket/websocket.pid";

    private $_host;
    private $_port;

    private $_localHost;
    private $_localPort;

    /**
     * @var Worker
     */
    private $_worker;

    public function __construct($host, $port)
    {
        $this->_host = $host;
        $this->_port = $port;
    }

    public function setLocalServer($host, $port)
    {
        $this->_localHost = $host;
        $this->_localPort = $port;
    }

    public function stop()
    {
        Log::Debug("STOP!!!");

        $this->_worker->stop();
    }

    public function start()
    {
        Log::Debug("START...");

        // open server socket
        $server = stream_socket_server("tcp://{$this->_host}:{$this->_port}", $errorNumber, $errorString);
        stream_set_blocking($server, false);

        if (!$server) {
            die("error: stream_socket_server: $errorString ($errorNumber)\r\n");
        }

        // open local socket
        $service = stream_socket_server("tcp://{$this->_localHost}:{$this->_localPort}", $errorNumber, $errorString);
        stream_set_blocking($service, false);

        if (!$service) {
            die("error: stream_socket_server: $errorString ($errorNumber)\r\n");
        }

        $master = null;

        $this->_worker = new Handler($server, $service, $master);
        $this->_worker->start();
    }

    public function daemonize()
    {
        Log::Debug("Daemonize");

        if ($pid = @file_get_contents(self::PIDFILE)) {
            if (posix_kill($pid, 0)) {
                die("Daemon already active\r\n");
            } else {
                if (!unlink(self::PIDFILE)) {
                    exit(-1);
                }
            }
        }

        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('websocket');
        }

        $childPid = pcntl_fork();

        if ($childPid) {
            exit();
        }

        posix_setsid();

        $logDir = dirname(__FILE__) . '/../log';

        if (!is_dir($logDir)) {
            mkdir($logDir);
        }

        ini_set('error_log', $logDir . '/error.log');

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen($logDir . '/app.log', 'ab');
        $STDERR = fopen($logDir . '/daemon.log', 'ab');

        pcntl_signal(SIGTERM, function ($signal) {
            $this->stop();
        });

        file_put_contents(self::PIDFILE, posix_getpid());

        $this->start();
    }

}