<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Protocols;

use Tak\Liveproto\Network\TcpClient;

use Tak\Liveproto\Utils\Binary;

final class TcpPaddedIntermediate {
	public function __construct(? TcpClient $tcpClient = null){
		is_null($tcpClient) || $tcpClient->write(str_repeat(chr(221),4));
	}
	public function encode(string $body) : string {
		$binary = new Binary();
		$padding = strlen($body) % 16 ? 0x10 - strlen($body) % 0x10 : 0;
		$binary->writeInt(strlen($body) + $padding);
		$binary->write($body.random_bytes($padding));
		return $binary->read();
	}
	public function decode(object $tcpClient) : string {
		$exception = new \Exception('The connection with the server is not established !');
		$lengthBytes = $tcpClient->read(4);
		assert(empty($lengthBytes) === false,$exception);
		$length = unpack('V',$lengthBytes)[true];
		$body = $tcpClient->read($length);
		assert(empty($body) === false,$exception);
		return $body;
	}
}

?>