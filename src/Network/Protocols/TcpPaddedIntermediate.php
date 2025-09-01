<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Protocols;

use Tak\Liveproto\Network\TcpClient;

use Tak\Liveproto\Errors\TransportError;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Binary;

final class TcpPaddedIntermediate {
	public function __construct(? TcpClient $tcpClient = null){
		$tcpClient?->write(str_repeat(chr(221),4));
	}
	public function encode(string $body) : string {
		$binary = new Binary();
		$padding = strlen($body) % 16 ? 0x10 - strlen($body) % 0x10 : 0;
		$binary->writeInt(strlen($body) + $padding);
		$binary->write($body.random_bytes($padding));
		return $binary->read();
	}
	public function decode(object $tcpClient) : string {
		$exception = new \RuntimeException('The connection with the server is not established !');
		$lengthBytes = $tcpClient->read(4);
		assert(empty($lengthBytes) === false,$exception);
		$length = Helper::unpack('V',$lengthBytes);
		$body = $tcpClient->read($length);
		assert(empty($body) === false,$exception);
		if($length === 0x4):
			$code = Helper::unpack('l',$body);
			assert($code >= 0,new TransportError(self::class,$code));
		endif;
		return $body;
	}
}

?>