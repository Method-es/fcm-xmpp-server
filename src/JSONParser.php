<?php

namespace Method\FCM;

use Evenement\EventEmitter;

class JSONParser extends EventEmitter
{
    public function parse(string $data)
    {
        $jsonData = json_decode($data);

        //determine upstream message, or downstream message
//        $messageID = $jsonData->message_id;
//        $fromID = $jsonData->from;

        if(isset($jsonData->message_type)){
            //downstream RESPONSE
            if($jsonData->message_type == "ack"){
                $this->emit('message.downstream.acknowledged',[$jsonData, $this]);
            }else if($jsonData->message_type == "nack"){
                $this->emit('message.downstream.not_acknowledged',[$jsonData, $this]);
            }else{
                $this->emit('message.downstream.unhandled',[$jsonData, $this]);
            }
        }else{
            $this->emit('message.upstream.received', [$jsonData, $this]);
        }
    }
}