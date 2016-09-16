<?php

namespace Method\FCM;

use Evenement\EventEmitter;
use League\CLImate\CLImate;
use React\EventLoop\LoopInterface;
use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use SimpleXMLElement;

class AppServer extends EventEmitter
{
    const REMOTE_HOST = 'fcm-xmpp.googleapis.com';
    const FCM_DOMAIN = "gcm.googleapis.com";

    /**
     * @var bool
     */
    private $authed = false;
    /**
     * @var string
     */
    private $senderID;
    /**
     * @var string
     */
    private $serverKey;

    /**
     * @var int
     */
    private $remotePort;

    /**
     * @var LoopInterface
     */
    private $eventLoop;

    /**
     * @var ConnectorInterface
     */
    private $connector;
    /**
     * @var Stream
     */
    private $stream;

    /**
     * @var XMLStreamParser
     */
    private $xmlParser;
    /**
     * @var JSONParser
     */
    private $jsonParser;

    /**
     * @var CLImate
     */
    private $output;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector, string $senderID, string $serverKey, int $port = 5235)
    {
        $this->remotePort = $port;
        $this->serverKey = $serverKey;
        $this->senderID = $senderID;

        $this->eventLoop = $loop;
        $this->connector = $connector;

        $this->jsonParser = new JSONParser();
        $this->xmlParser = new XMLStreamParser();

        $this->output = new CLImate;

        $this->setupFCMEvents();
    }

    public function onStreamStart(array $attrs, XMLStreamParser $parser)
    {
        $this->output->info('Stream Started (ID: '.($attrs['id'] ?? ('unknown')).')');
    }

    public function onStreamNode(SimpleXMLElement $element, XMLStreamParser $parser)
    {
        $nodeName = $element->getName();
        $this->output->whisper('[EVENT] Node: '.$nodeName);
        //okay if we have a features node; there are two possibilities; and it's all based on if we are successfully authed yet.
        switch($nodeName){
            case "features":
                if(!$this->authed && isset($element->mechanisms)){
                    //we are not authed yet, so a features node needs to be responded with an auth request
                    $this->output->info('Authorizing Stream');
                    $this->stream->write('<auth mechanism="PLAIN" xmlns="urn:ietf:params:xml:ns:xmpp-sasl">'.$this->getFCMAuthToken().'</auth>');
                }else if(isset($element->bind)){
                    $this->output->info('Binding Stream');
                    $this->stream->write('<iq id="'.$parser->getStreamID().'" type="set"><bind xmlns="urn:ietf:params:xml:ns:xmpp-bind"/></iq>');
                }else{
                    $this->output->error('[ERROR] UNKNOWN Features Element:');
                    $this->output->dump($element->asXML());
                }
                break;
            case "success":
                if(!$this->authed){
                    $this->output->info('Stream Authorized');
                    $this->authed = true;
                    $this->stream->write('<stream:stream to="'.self::REMOTE_HOST.'" version="1.0" xmlns="jabber:client" xmlns:stream="http://etherx.jabber.org/streams">');
                }
                break;
            case 'iq':
                if($element['type'] == "result"){
                    //finalising the bind
                    $this->output->info('Stream Bound');
                }
                break;
            case 'message':
                $this->output->info('Message Received');
//                $output->dump($element->asXML());

                $this->jsonParser->parse((string)$element->gcm);
                break;
            default:
                $this->output->error('UN HANDLED NODE');
                $this->output->dump($element->asXML());
                break;
        }
    }

    public function onUpstreamMessageReceived($jsonData, JSONParser $parser)
    {
        $regID = $jsonData->from;
        $msgID = $jsonData->message_id;
        $remoteHost = self::REMOTE_HOST;

        //lets ack the message
        $returnMessage = <<<XML
<message id="{$this->xmlParser->getStreamID()}" to="{$remoteHost}">
    <gcm xmlns="google:mobile:data">
        {
            "to":"{$regID}",
            "message_id":"{$msgID}"
            "message_type":"ack"
        }
    </gcm>
</message>
XML;
        $this->output->info('Message Acknowledged: [ID:'.$msgID.' FROM: '.$regID.']');
        $this->stream->write($returnMessage);

        $downstreamMessageID = uniqid("",true);
        if(isset($jsonData->data)){
            $downstreamMessageData = json_encode($jsonData->data);
        }else{
            $downstreamMessageData = json_encode(['placeholder'=>'sup bro.']);
        }

        $downstreamMessage = <<<XML
<message id="{$this->xmlParser->getStreamID()}" to="{$remoteHost}">
  <gcm xmlns="google:mobile:data">
  {
      "to":"{$regID}",
      "message_id":"{$downstreamMessageID}"
      "data":{$downstreamMessageData}
  }
  </gcm>
</message>
XML;
        $this->output->info("Sending Data as Downstream Message [ID: {$downstreamMessageID}]");
        $this->stream->write($downstreamMessage);
    }

    public function onDownstreamMessageAcknowledged($jsonData, JSONParser $parser)
    {
        $this->output->info("Downstream Message Acknowledged [ID: {$jsonData->message_id}]");
    }

    public function onDownstreamMessageNotAcknowledged($jsonData, JSONParser $parser)
    {
        $this->output->error('[ERROR] Not Acknowledged Downstream Message');
        $this->output->dump($jsonData);
    }

    public function onDownstreamUnhandled($jsonData, JSONParser $parser)
    {
        $this->output->error('[ERROR] Unhandled Downstream Message');
        $this->output->dump($jsonData);
    }

    protected function setupFCMEvents()
    {
        $this->xmlParser->on('stream.started', [$this, "onStreamStart"]);
        $this->xmlParser->on('stream.node', [$this, "onStreamNode"]);

        $this->jsonParser->on('message.upstream.received', [$this, "onUpstreamMessageReceived"]);
        $this->jsonParser->on('message.downstream.acknowledged', [$this, "onDownstreamMessageAcknowledged"]);
        $this->jsonParser->on('message.downstream.not_acknowledged', [$this, "onDownstreamMessageNotAcknowledged"]);
        $this->jsonParser->on('message.downstream.unhandled', [$this, "onDownstreamUnhandled"]);
    }

    public function onStreamData($data, $stream)
    {
        $this->xmlParser->parse($data);
    }

    public function onStreamError($error, $stream)
    {
        $this->output->error($error);
        var_dump('error',$error);
    }

    public function onStreamClose($stream)
    {
        var_dump('closed');
    }

    public function onStreamEnd($stream)
    {
        var_dump('end');
    }

    protected function setupStreamEvents()
    {
        $this->stream->on('data', [$this, "onStreamData"] );
        $this->stream->on('error', [$this, "onStreamError"]);
        $this->stream->on('close', [$this, "onStreamClose"]);
        $this->stream->on('end', [$this, "onStreamEnd"]);
    }

    protected function getFCMAuthToken()
    {
        return base64_encode(chr(0).$this->getFCMUser().chr(0).$this->serverKey);
    }

    protected function getFCMUser()
    {
        return $this->senderID."@".self::FCM_DOMAIN;
    }

    public function onConnect(Stream $stream)
    {
        $this->stream = $stream;
        $this->setupStreamEvents();
        $this->stream->write('<stream:stream to="'.self::REMOTE_HOST.'" version="1.0" xmlns="jabber:client" xmlns:stream="http://etherx.jabber.org/streams">');
    }

    public function connect()
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $this->connector->create(self::REMOTE_HOST, $this->remotePort)->then([$this,"onConnect"]);
    }

    public function start()
    {
        $this->eventLoop->run();
    }

}