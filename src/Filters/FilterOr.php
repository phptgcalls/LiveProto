<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Filters;

final class FilterOr {
	private array $types;

	public function __construct(\ReflectionUnionType $object){
		$this->types = $object->getTypes();
	}
	public function check(object $update) : bool {
		$conditions = array();
		foreach($this->types as $type):
			$condition = match(true){
				$type instanceof \ReflectionIntersectionType => new FilterAnd($type),
				$type instanceof \ReflectionNamedType => new FilterName($type),
				default => throw new \Exception('Unknown Type Filter !')
			};
			$conditions []= $condition->check($update);
		endforeach;
		return in_array(true,$conditions) === true;
	}
}

?>