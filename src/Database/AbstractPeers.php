<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Database;

interface AbstractPeers {
	public function initPeer(string $table) : bool;

	public function setPeer(string $table,mixed $value) : void;

	public function getPeer(string $table,string $key,mixed $value) : ? array;
	
	public function deletePeer(string $table,string $key,mixed $value) : void;
}

?>