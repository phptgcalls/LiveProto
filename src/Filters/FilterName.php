<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Filters;

final class FilterName {
	private string $condition;

	public function __construct(\ReflectionNamedType $object){
		$this->condition = $object->getName();
	}
	public function check(object $update) : bool {
		$interfaces = new Interfaces($this->condition);
		return $interfaces->check($update);
	}
}

?>