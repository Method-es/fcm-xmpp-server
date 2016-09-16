# fcm-xmpp-server




Example:

```PHP

use Method\FCM\AppServer;

require __DIR__ . "/vendor/autoload.php";

define('SENDER_ID', '--SENDER_ID--');
define('SERVER_KEY', '--SERVER_KEY--');


//main loop
$loop = React\EventLoop\Factory::create();

//tcp connector, for underlying layer
$tcpConnector = new React\SocketClient\TcpConnector($loop);

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

//dns connector, for resolving hostnames (decorates the tcp layer)
$dnsConnector = new React\SocketClient\DnsConnector($tcpConnector, $dns);

//secure connector, for ssl/tls (decorates the dns/tcp connector)
$secureConnector = new React\SocketClient\SecureConnector($dnsConnector, $loop);

$FCMServer = new AppServer($loop, $secureConnector, SENDER_ID, SERVER_KEY);

$FCMServer->connect();

$FCMServer->start();
```