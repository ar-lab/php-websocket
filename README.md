# php-websocket

Websocket server on PHP

## System Daemon

Put daemon script `etc-init-d-websocket` to `/etc/init.d` directory and rename to `websocket` 

Available commands:

- `service websocket status`
- `service websocket start`
- `service websocket stop`
- `service websocket restart`

## Message from PHP

```php
$message = 'test';

$localSocket = 'tcp://127.0.0.1:8010';
$instance = stream_socket_client($localSocket, $errno, $errstr);
fwrite($instance, $message);
```

## Client

Open connection

```javascript
var webSocket = new WebSocket('ws://yourdomain:8000');
```

Receive messages

```javascript
webSocket.onopen = function(event) {
    console.log('websocket connection opened');
};

webSocket.onclose = function(event) {
    console.log('websocket connection closed');
};

webSocket.onerror = function(error) {
    console.log('websocket connection error');
    alert(error.message);
};

webSocket.onmessage = function(event) {
    console.log('New message received');
    alert(event.data);
};
```

Send message

```javascript
webSocket.send("Hello!");
```
