<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Protocols;

use Tak\Liveproto\Network\TcpClient;

use Tak\Liveproto\Errors\TransportError;

use Tak\Liveproto\Utils\Helper;

final class TcpAbridged {
	public function __construct(? TcpClient $tcpClient = null){
		$tcpClient?->write(chr(239));
	}
	public function encode(string $body) : string {
		$length = strlen($body) >> 2;
		if($length < 0x7f):
			$message = chr($length);
		else:
			$message = chr(0x7f).substr(Helper::pack('V',$length),0,3);
		endif;
		return $message.$body;
	}
	public function decode(object $tcpClient) : string {
		$exception = new \RuntimeException('The connection with the server is not established !');
		$lengthByte = $tcpClient->read(1);
		assert(empty($lengthByte) === false,$exception);
		$length = ord($lengthByte);
		if($length >= 0x7f):
			$lengthBytes = strval($tcpClient->read(3).chr(0));
			$length = Helper::unpack('V',$lengthBytes);
		endif;
		$length = $length << 2;
		$body = $tcpClient->read($length);
		assert(empty($body) === false,$exception);
		if($length === 0x4):
			$code = Helper::unpack('l',$body);
			assert($code >= 0,new TransportError($code));
		endif;
		return $body;
	}
}

?>