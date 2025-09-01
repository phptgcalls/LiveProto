<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Protocols;

use Tak\Liveproto\Errors\Security;

use Tak\Liveproto\Errors\TransportError;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Binary;

final class TcpFull {
	private int $client_seqno = 0;
	private int $server_seqno = 0;

	public function encode(string $body) : string {
		$binary = new Binary();
		$binary->writeInt(strlen($body) + 12);
		$binary->writeInt($this->client_seqno);
		$this->client_seqno++;
		$binary->write($body);
		$message = $binary->read();
		$crc = crc32($message);
		$binary->write($message);
		$binary->writeInt($crc);
		return $binary->read();
	}
	public function decode(object $tcpClient) : string {
		$exception = new \RuntimeException('The connection with the server is not established !');
		$invalid = new Security('The response is invalid !');
		$packetBytes = $tcpClient->read(4);
		assert(empty($packetBytes) === false,$exception);
		$packet = Helper::unpack('l',$packetBytes);
		assert($packet >= 0,new TransportError(self::class,$packet));
		assert($packet > 12,$invalid);
		$seqBytes = $tcpClient->read(4);
		assert(empty($seqBytes) === false,$exception);
		$seq = Helper::unpack('V',$seqBytes);
		assert($seq === $this->server_seqno,$invalid);
		$this->server_seqno++;
		$body = $tcpClient->read($packet - 12);
		assert(empty($body) === false,$exception);
		$sum = $tcpClient->read(4);
		assert(empty($sum) === false,$exception);
		$checksum = Helper::unpack('V',$sum);
		$validchecksum = crc32($packetBytes.$seqBytes.$body);
		assert($checksum === $validchecksum,$invalid);
		return $body;
	}
}

?>