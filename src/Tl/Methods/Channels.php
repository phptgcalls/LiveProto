<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

trait Channels {
	public function get_input_channel(
		string | int | null | object $channel,
		string | int | null | object $peer = null,
		? int $msg_id = null
	) : mixed {
		$entity = $this->get_input_peer($channel);
		$class = $entity->getClass();
		return match($class){
			'inputPeerEmpty' => $this->inputChannelEmpty(),
			'inputPeerChannel' => $this->inputChannel(channel_id : $entity->channel_id,access_hash : $entity->access_hash),
			'inputPeerChannelFromMessage' => (is_null($peer) === false and is_null($msg_id) === false) ? $this->inputChannelFromMessage(peer : $this->get_input_peer($peer),msg_id : $msg_id,channel_id : $entity->channel_id) : throw new \InvalidArgumentException('The `peer` and `msg_id` parameters must be filled'),
			default => throw new \InvalidArgumentException('This entity('.$class.') does not belong to a channel !')
		};
	}
}

?>