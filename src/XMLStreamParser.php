<?php

namespace Method\FCM;

use Evenement\EventEmitter;
use SimpleXMLElement;

class XMLStreamParser extends EventEmitter
{
    private $xmlParser;
    private $depth = 0;

    private $streamID;
    private $rootElement;
    private $elementStack = [];
//    private $streamStarted = false;
    private $rootAttributes = [];
    function __construct()
    {
        $this->xmlParser = xml_parser_create();

        xml_set_object($this->xmlParser, $this);
        xml_set_element_handler($this->xmlParser, "startElement", "endElement");
        xml_set_character_data_handler($this->xmlParser, "onData");
        xml_parser_set_option($this->xmlParser, XML_OPTION_CASE_FOLDING, false);
    }

    function getStreamID()
    {
        return $this->streamID;
    }

    function parse($data)
    {
        xml_parse($this->xmlParser, $data);
    }

    function __destruct()
    {
        xml_parser_free($this->xmlParser);
    }

    function startElement($parser, $name, $attrs)
    {
        //normally we use the element stack for processing; but if this is our OPENING xml element;
        // then the stream has just started, and it does not go on the stack. Instead, it will be saved separately
        if(strcasecmp($name, 'stream:stream') === 0){
            $this->rootAttributes = $attrs;
            $this->streamID = $this->rootAttributes['id'] ?? uniqid();
            $this->emit('stream.started', [$this->rootAttributes,$this]);

            return;
        }

        $namespace = null;
        $nodeName = $name;
        if(strpos($name,":") !== false){
            list($namespace,$nodeName) =  explode(":",$name);
        }

        if($this->depth == 0){
            $node = new SimpleXMLElement("<".$nodeName." />", 0, false, $namespace);
            $this->rootElement = $node;
        }else{
            $parentElement = end($this->elementStack);
            $node = $parentElement->addChild($nodeName, null, $namespace);
        }

        foreach($attrs as $attrName => $attrValue){
            $node->addAttribute($attrName, $attrValue);
        }

        $this->elementStack[] = $node;

        $this->depth++;
    }

    function endElement($parser, $name)
    {
        $this->depth--;
        array_pop($this->elementStack);
        if($this->depth === 0){
            //dispatch event...
            $this->emit('stream.node',[clone $this->rootElement, $this]);
        }
    }

    function onData($parser, $data)
    {
        $parentElement = end($this->elementStack);
        $parentElement[0] = $data;
    }
}