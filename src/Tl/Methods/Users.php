<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

trait Users {
	public function get_input_user(
		string | int | null | object $user,
		string | int | null | object $peer = null,
		? int $msg_id = null
	) : mixed {
		$entity = $this->get_input_peer($user);
		$class = $entity->getClass();
		return match($class){
			'inputPeerEmpty' => $this->inputUserEmpty(),
			'inputPeerSelf' => $this->inputUserSelf(),
			'inputPeerUser' => (is_null($peer) === false and is_null($msg_id) === false) ? $this->inputUserFromMessage(peer : $this->get_input_peer($peer),msg_id : $msg_id,user_id : $entity->user_id) : $this->inputUser(user_id : $entity->user_id,access_hash : $entity->access_hash),
			default => throw new \InvalidArgumentException('This entity('.$class.') does not belong to a user !')
		};
	}
	public function get_me() : object {
		return $this->get_peer('me');
	}
	public function is_bot() : bool {
		return boolval($this->get_me()->bot);
	}
}

?>