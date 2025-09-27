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

final class HttpSocketConnector {
	use ForbidCloning;
	use ForbidSerialization;

	static public function tunnel(Socket $socket,string $target,? string $username,? string $password,? Cancellation $cancellation) : void {
		if(is_null($username) or is_null($password)):
			$authHeader = strval(null);
		else:
			$authHeader = 'Proxy-Authorization: Basic '.base64_encode($username.':'.$password).CRLF;
		endif;
		$uri = Http::new($target);
		$host = $uri->getHost() ?: throw new SocketException('Host is empty !');
		$port = $uri->getPort();
		$ip = inet_pton($host);
		if($ip !== false and strlen($ip) === 16):
			$host = sprintf('[%s]',$ip);
		endif;
		$socket->write('CONNECT '.$host.':'.$port.' HTTP/1.1'.CRLF.'Host: '.$host.':'.$port.CRLF.'Accept: */*'.CRLF.$authHeader.'Connection: keep-Alive'.CRLF.CRLF);
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
		$headers = strval(null);
		do {
			$piece = $read(2);
			$headers .= $piece;
			if($piece === LFCR):
				$headers .= $read(1);
				break;
			elseif(str_ends_with($headers,CRLF.CRLF)):
				break;
			endif;
		} while(true);
		$headers = explode(CRLF,$headers);
		list($protocol,$code,$description) = explode(chr(32),array_shift($headers),3);
		list($protocol,$version) = explode(chr(47),$protocol);
		if($protocol !== 'HTTP'):
			throw new SocketException('Wrong protocol : '.$protocol);
		elseif(array_pop($headers).array_pop($headers) !== strval(null)):
			throw new SocketException('Wrong last HTTP header');
		elseif($code != 200):
			throw new SocketException($code.chr(32).$description,intval($code));
		endif;
		$headers = array_change_key_case(array_column(array_map(fn(string $item) : array => array_map('trim',explode(chr(58),$item,2)),$headers),1,0));
		if(isset($headers['content-length'])):
			$length = intval($headers['content-length']);
			if($length > 0):
				$content = $read($length);
			endif;
		endif;
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