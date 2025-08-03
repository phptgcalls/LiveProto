<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network;

use Tak\Liveproto\Enums\ProtocolType;

final class TcpTransport {
	public object $tcpClient;
	private object $protocol;

	public function __construct(string $ip,int $port,int $dcid,? ProtocolType $protocol = null,? array $proxy = null){
		$this->tcpClient = new TcpClient();
		$this->tcpClient->connect($ip,$port,$proxy);
		if(is_null($proxy) === false and strtoupper($proxy['type']) === 'MTPROXY'):
			$protocol = ProtocolType::OBFUSCATED;
		else:
			$protocol = is_null($protocol) ? ProtocolType::FULL : $protocol;
		endif;
		$class = strval('\\Tak\\Liveproto\\Network\\Protocols\\'.$protocol->value);
		$this->protocol = match(true){
			$protocol === ProtocolType::ABRIDGED => new $class(tcpClient : $this->tcpClient),
			$protocol === ProtocolType::INTERMEDIATE => new $class(tcpClient : $this->tcpClient),
			$protocol === ProtocolType::PADDEDINTERMEDIATE => new $class(tcpClient : $this->tcpClient),
			$protocol === ProtocolType::OBFUSCATED => new $class(tcpClient : $this->tcpClient,dcid : $dcid,secret : (is_null($proxy) ? null : $proxy['secret'])),
			$protocol === ProtocolType::HTTP => new $class(host : $ip,port : $port),
			default => new $class
		};
	}
	public function send(string $packet) : void {
		if($this->tcpClient->connected):
			try {
				$this->tcpClient->write($this->protocol->encode($packet));
			} catch(\Throwable $error){
				throw $error;
			}
		endif;
	}
	public function receive() : string {
		if($this->tcpClient->connected):
			try {
				return $this->protocol->decode($this->tcpClient);
			} catch(\Throwable $error){
				
				throw $error;
			}
		endif;
	}
	public function close() : void {
		$this->tcpClient->close();
	}
}

?>