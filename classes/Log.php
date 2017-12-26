<?php

class Log
{

    static function Error($message)
    {
        self::WriteLog('Error: ' . $message);
    }

    static function Warning($message)
    {
        self::WriteLog('Warning: ' . $message);
    }

    static function Notice($message)
    {
        self::WriteLog('Notice: ' . $message);
    }

    static function Debug($message)
    {
        self::WriteLog('Debug: ' . $message);
    }

    static private function WriteLog($message)
    {
        $time = date('Y-m-d H:i:s');
        $logStr = "[$time] $message" . PHP_EOL;

        echo $logStr;
    }

}