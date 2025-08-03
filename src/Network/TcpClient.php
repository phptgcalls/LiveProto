<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network;

use Tak\Liveproto\Network\Proxy\Socks5SocketConnector;

use Tak\Liveproto\Network\Proxy\HttpSocketConnector;

use Amp\Socket\Socket;

use Amp\Socket\ConnectContext;

use Amp\Socket\ClientTlsContext;

use Amp\TimeoutCancellation;

use League\Uri\Http;

use function Amp\Socket\connect;

final class TcpClient {
	private ConnectContext $context;
	private Socket $socket;
	public bool $connected;

	public function __construct(float $timeout = 10,? int $dns = null,bool $nodelay = false){
		$context = new ConnectContext();
		$context = $context->withConnectTimeout($timeout);
		$context = $context->withDnsTypeRestriction($dns);
		$context = ($nodelay ? $context->withTcpNoDelay() : $context->withoutTcpNoDelay());
		$this->context = $context;
		$this->connected = false;
	}
	public function connect(string $ip,int $port,? array $proxy = null,bool $secure = false) : void {
		if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)):
			$uri = sprintf('tcp://[%s]:%d',$ip,$port);
		elseif(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)):
			$uri = sprintf('tcp://%s:%d',$ip,$port);
		else:
			throw new \Exception('Invalid IP !');
		endif;
		if($secure === true):
			$this->context = $this->context->withTlsContext(new ClientTlsContext(Http::new($uri)->getHost()));
		endif;
		if(is_null($proxy)):
			$this->socket = connect($uri,$this->context);
		else:
			if(strtoupper($proxy['type']) === 'SOCKS5'):
				$socks5 = new Socks5SocketConnector(proxyAddress : $proxy['address'],username : $proxy['username'],password : $proxy['password']);
				$this->socket = $socks5->connect($uri,$this->context);
			elseif(strtoupper($proxy['type']) === 'HTTP'):
				$http = new HttpSocketConnector(proxyAddress : $proxy['address'],username : $proxy['username'],password : $proxy['password']);
				$this->socket = $http->connect($uri,$this->context);
			elseif(strtoupper($proxy['type']) === 'MTPROXY'):
				$uri = sprintf('tcp://%s',$proxy['address']);
				$this->socket = connect($uri,$this->context);
			else:
				throw new \Exception('Invalid proxy type !');
			endif;
		endif;
		$this->socket->setChunkSize(PHP_INT_MAX);
		$this->connected = true;
	}
	public function close() : void {
		if($this->connected):
			$this->connected = false;
			$this->socket->close();
		endif;
	}
	public function write(string $data) : void {
		if($this->connected):
			$this->socket->write($data);
		else:
			throw new \Exception('First you need to connect to the server !');
		endif;
	}
	public function read(int $size,int $timeout = 60) : string {
		$result = (string) null;
		while($size > strlen($result)):
			if($this->connected):
				$read = $this->socket->read($timeout > 0 ? new TimeoutCancellation($timeout) : null,$size - strlen($result));
				$result .= $read;
			else:
				throw new \Exception('First you need to connect to the server !');
			endif;
		endwhile;
		return $result;
	}
}

?>