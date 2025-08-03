<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Protocols;

use Tak\Liveproto\Crypto\Obfuscation;

use Tak\Liveproto\Enums\ProtocolType;

use Tak\Liveproto\Network\TcpClient;

use Tak\Liveproto\Network\Protocols\TcpPaddedIntermediate;

use Tak\Liveproto\Utils\Logging;

final class TcpObfuscated {
	private TcpPaddedIntermediate $protocol;
	protected Obfuscation $obfuscation;

	public function __construct(TcpClient $tcpClient,int $dcid,bool $testmode = false,bool $mediaDC = false,? string $secret = null){
		$this->protocol = new TcpPaddedIntermediate();
		$this->obfuscation = new Obfuscation(protocol : ProtocolType::PADDEDINTERMEDIATE,dcid : $dcid,testmode : $testmode,mediaDC : $mediaDC,secret : $secret);
		$tcpClient->write($this->obfuscation->init);
	}
	public function encode(string $body) : string {
		return $this->obfuscation->encrypt($this->protocol->encode($body));
	}
	public function decode(object $tcpClient) : string {
		$tcpObfuscation = new class($tcpClient,$this->obfuscation){
			public function __construct(protected object $tcpClient,protected Obfuscation $obfuscation){
			}
			public function read(int $size,int $timeout = 60) : string {
				return $this->obfuscation->decrypt($this->tcpClient->read(size : $size,timeout : $timeout));
			}
		};
		return $this->protocol->decode($tcpObfuscation);
	}
}

?>