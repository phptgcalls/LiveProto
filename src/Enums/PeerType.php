<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum PeerType : string {
	case SELF = 'self';
	case USER = 'user';
	case BOT = 'bot';
	case CHAT = 'chat';
	case GIGAGROUP = 'gigagroup';
	case MEGAGROUP = 'megagroup';
	case CHANNEL = 'channel';
	case UNKNOWN = 'unknown';

	public function getChatType() : string {
		return match($this){
			self::SELF , self::USER , self::BOT => 'private',
			self::CHAT => 'group',
			self::GIGAGROUP , self::MEGAGROUP => 'supergroup',
			self::CHANNEL => 'channel',
			default => 'unknown'
		};
	}
}

?>