<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl;

final class All {
	static public function getConstructor(int $constructorId) : object {
		return match(intval($constructorId)){
			default => throw new \Exception('Please rerun TL Generator again because constructor '.$constructorId.' not found !')
		};
	}
}

?>