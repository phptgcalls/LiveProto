<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum Endianness : int {
	case BIG = 1;
	case LITTLE = -1;
	case UNKNOWN = 0;

	public static function detect() : self {
		$hex = bin2hex(pack('S',0x0102));
		return match($hex){
			'0102' => self::BIG,
			'0201' => self::LITTLE,
			default => self::UNKNOWN
		};
	}
	public function isBig() : bool {
		return $this === self::BIG;
	}
	public function isLittle() : bool {
		return $this === self::LITTLE;
	}
}

?>