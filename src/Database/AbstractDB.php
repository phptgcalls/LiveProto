<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Database;

interface AbstractDB {
	public function init(string $table) : bool;

	public function set(string $table,string $key,mixed $value,string $type) : void;

	public function get(string $table) : ? array;

	public function delete(string $table,string $key) : void;

	public function exists(string $table,string $key) : bool;
}

?>