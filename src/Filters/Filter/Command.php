<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Filters\Filter;

use Tak\Liveproto\Filters\Filter;

final class Command extends Filter {
	public function __construct(object | array | string ...$commands){
		$this->items = $commands;
	}
	public function apply(object $update) : array | false {
		$regex = array_map(fn(string | int $key,object | array | string $value) : string => ('~^(?<prefix>\\'.strval(is_int($key) ? '/' : ($value instanceof \BackedEnum ? (is_string($value->value) ? $value->value : '/') : (is_array($value) ? implode('|\\',array_map(fn($type) : string => strval($type instanceof \BackedEnum ? (is_string($type->value) ? $type->value : '/') : $type),$value)) : $value))).')(?<command>'.strval(is_int($key) ? $value : $key).')(?<space>\s*)(?<parameter>.*)~'),array_keys($this->items),array_values($this->items));
		$check = new Regex(...$regex);
		return $check->apply($update);
	}
}

?>