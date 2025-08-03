<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum ProtocolType : string {
	case FULL = 'TcpFull';
	case ABRIDGED = 'TcpAbridged';
	case INTERMEDIATE = 'TcpIntermediate';
	case PADDEDINTERMEDIATE = 'TcpPaddedIntermediate';
	case OBFUSCATED = 'TcpObfuscated';
	case HTTP = 'Http';

	public function toBytes() : string {
		return gmp_export(match($this){
			self::ABRIDGED => 0xefefefef,
			self::INTERMEDIATE => 0xeeeeeeee,
			self::PADDEDINTERMEDIATE => 0xdddddddd,
			self::HTTP => 0x54534f50,
			default => throw new \Exception('There is no bytes for this protocol !')
		});
	}
}

?>