<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Proxy;

use Amp\Cancellation;

use Amp\ForbidCloning;

use Amp\ForbidSerialization;

use Amp\Socket\Socket;

use Amp\Socket\ConnectContext;

use Amp\Socket\ClientTlsContext;

use Amp\Socket\SocketException;

use League\Uri\Http;

use function Amp\Socket\socketConnector;

final class Socks5SocketConnector {
	use ForbidCloning;
	use ForbidSerialization;

	private const REPLIES = [
		0 => 'Succeeded',
		1 => 'General SOCKS server failure',
		2 => 'Connection not allowed by ruleset',
		3 => 'Network unreachable',
		4 => 'Host unreachable',
		5 => 'Connection refused',
		6 => 'TTL expired',
		7 => 'Command not supported',
		8 => 'Address type not supported'
	];

	static public function tunnel(Socket $socket,string $target,? string $username,? string $password,? Cancellation $cancellation) : void {
		if(is_null($username) !== is_null($password)):
			throw new SocketException('Both or neither username and password must be provided !');
		endif;
		$methods = chr(0);
		if(isset($username) and isset($password)) $methods .= chr(2);
		$socket->write(chr(5).chr(strlen($methods)).$methods);
		$read = function(int $length) use($socket,$cancellation) : string {
			assert($length > 0);
			$buffer = strval(null);
			do {
				$limit = $length - strlen($buffer);
				assert($limit > 0);
				$chunk = $socket->read($cancellation,$limit);
				if($chunk === null):
					throw new SocketException('The socket was closed before the tunnel could be established');
				endif;
				$buffer .= $chunk;
			} while(strlen($buffer) !== $length);
			return $buffer;
		};
		$version = ord($read(1));
		if($version !== 5):
			throw new SocketException('Wrong SOCKS5 version : '.$version);
		endif;
		$method = ord($read(1));
		if($method === 2):
			if(is_null($username) or is_null($password)):
				throw new SocketException('Unexpected method : '.$method);
			endif;
			$socket->write(chr(1).chr(strlen($username)).$username.chr(strlen($password)).$password);
			$version = ord($read(1));
			if($version !== 1):
				throw new SocketException('Wrong authorized SOCKS version : '.$version);
			endif;
			$result = ord($read(1));
			if($result !== 0):
				throw new SocketException('Wrong authorization status : '.$result);
			endif;
		elseif($method !== 0):
			throw new SocketException('Unexpected method : '.$method);
		endif;
		$uri = Http::new($target);
		$host = $uri->getHost() ?: throw new SocketException('Host is empty !');
		$port = $uri->getPort();
		$ip = inet_pton($host);
		$payload = pack('C3',0x5,0x1,0x0);
		if($ip !== false):
			$payload .= chr(strlen($ip) === 4 ?  0x1 : 0x4).$ip;
		else:
			$payload .= chr(0x3).chr(strlen($host)).$host;
		endif;
		$payload .= pack('n',$port);
		$socket->write($payload);
		$version = ord($read(1));
		if($version !== 5):
			throw new SocketException('Wrong SOCKS5 version : '.$version);
		endif;
		$reply = ord($read(1));
		if($reply !== 0):
			$reply = self::REPLIES[$reply] ?? $reply;
			throw new SocketException('Wrong SOCKS5 reply : '.$reply);
		endif;
		$rsv = ord($read(1));
		if($rsv !== 0):
			throw new SocketException('Wrong SOCKS5 RSV : '.$rsv);
		endif;
		$read(match(ord($read(1))){
			0x1 => 4 + 2,
			0x4 => 16 + 2,
			0x3 => ord($read(1)) + 2
		});
	}
	public function __construct(private readonly string $proxyAddress,private readonly ? string $username = null,private readonly ? string $password = null,private ? object $connector = null){
		$this->connector ??= socketConnector();
	}
	public function connect(string $uri,ConnectContext $context = new ConnectContext,? Cancellation $cancellation = null,bool $secure = false) : Socket {
		if($secure):
			$context = $context->withTlsContext(new ClientTlsContext(Http::new($uri)->getHost()));
		endif;
		$socket = $this->connector->connect($this->proxyAddress,$context,$cancellation);
		self::tunnel($socket,strval($uri),$this->username,$this->password,$cancellation);
		if($secure):
			$socket->setupTls($cancellation);
		endif;
		return $socket;
	}
}

?>