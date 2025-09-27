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

final class Socks4SocketConnector {
	use ForbidCloning;
	use ForbidSerialization;

	private const REPLIES = [
		0x5a => 'Succeeded',
		0x5b => 'Request rejected or failed',
		0x5c => 'Request failed because client is not running identd',
		0x5d => 'Request different user id'
	];

	static public function tunnel(Socket $socket,string $target,? string $user,? Cancellation $cancellation) : void {
		$uri = Http::new($target);
		$host = $uri->getHost() ?: throw new SocketException('Host is empty !');
		$port = $uri->getPort();
		$ip = inet_pton($host);
		/*
		 * If host is an IPv4 address we will use SOCKS4. Otherwise , for domain names use SOCKS4a
		 * For SOCKS4a we must send 0.0.0.1 as DSTIP and append domain after USERID NUL
		 */
		$payload = pack('C2',0x4,0x1);
		$payload .= pack('n',$port);
		if(extension_loaded('iconv')):
			$user = @iconv('UTF-8','ASCII//TRANSLIT',strval($user));
		endif;
		if($ip !== false and strlen($ip) === 4):
			$payload .= $ip;
			$payload .= strval($user);
			$payload .= chr(0);
		else:
			$payload .= inet_pton('0.0.0.1');
			$payload .= strval($user);
			$payload .= chr(0);
			if(function_exists('idn_to_ascii')):
				$host = idn_to_ascii($host,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46) ?: $host;
			endif;
			$payload .= $host;
			$payload .= chr(0);
		endif;
		$socket->write($payload);
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
		if($version !== 0):
			throw new SocketException('Wrong SOCKS4 version : '.$version);
		endif;
		$reply = ord($read(1));
		if($reply !== 0x5a):
			$reply = self::REPLIES[$reply] ?? $reply;
			throw new SocketException('Wrong SOCKS4 reply : '.$reply);
		endif;
		$read(2);
		$read(4);
	}
	public function __construct(private readonly string $proxyAddress,private readonly ? string $user = null,private ? object $connector = null){
		$this->connector ??= socketConnector();
	}
	public function connect(string $uri,ConnectContext $context = new ConnectContext,? Cancellation $cancellation = null,bool $secure = false) : Socket {
		if($secure):
			$context = $context->withTlsContext(new ClientTlsContext(Http::new($uri)->getHost()));
		endif;
		$socket = $this->connector->connect($this->proxyAddress,$context,$cancellation);
		self::tunnel($socket,strval($uri),$this->user,$cancellation);
		if($secure):
			$socket->setupTls($cancellation);
		endif;
		return $socket;
	}
}

?>