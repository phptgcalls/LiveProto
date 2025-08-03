<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Filters\Filter;

use Tak\Liveproto\Filters\Filter;

final class Update extends Filter {
	public function __construct(string ...$updates){
		$this->items = $updates;
	}
	public function apply(object $update) : object | false {
		$class = $update->getClass();
		$check = array_map(fn(string $type) : bool => strcasecmp($class,$type) === 0,$this->items);
		return in_array(true,$check) ? $update : false;
	}
}

?>