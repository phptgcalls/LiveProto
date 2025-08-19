<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network\Protocols;

use Tak\Liveproto\Utils\Binary;

define('CRLF',chr(13).chr(10));

define('LFCR',chr(10).chr(13));

final class Http {
	public function __construct(private readonly string $host,private readonly int $port){
		$ip = inet_pton($host);
		if($ip !== false and strlen($ip) === 16):
			$this->host = sprintf('[%s]',$ip);
		endif;
	}
	public function encode(string $body) : string {
		return sprintf(
			'POST /api HTTP/1.1'.CRLF.
			'Host: %s:%d'.CRLF.
			'Content-Type: application/x-www-form-urlencoded'.CRLF.
			'Connection: keep-alive'.CRLF.
			'Keep-Alive: timeout=100000, max=10000000'.CRLF.
			'Content-Length: %d'.CRLF.CRLF.'%s',
			$this->host,
			$this->port,
			strlen($body),
			$body
		);
	}
	public function decode(object $tcpClient) : string {
		$headers = strval(null);
		do {
			$piece = $tcpClient->read(2);
			$headers .= $piece;
			if($piece === LFCR):
				$headers .= $tcpClient->read(1);
				break;
			elseif(str_ends_with($headers,CRLF.CRLF)):
				break;
			endif;
		} while(true);
		$headers = explode(CRLF,$headers);
		list($protocol,$code,$description) = explode(chr(32),array_shift($headers),3);
		list($protocol,$version) = explode(chr(47),$protocol);
		if($protocol !== 'HTTP'):
			throw new \Exception('Wrong protocol : '.$protocol);
		elseif(array_pop($headers).array_pop($headers) !== strval(null)):
			throw new \Exception('Wrong last HTTP header');
		elseif($code != 200):
			throw new \Exception($description,$code);
		endif;
		$headers = array_change_key_case(array_column(array_map(fn(string $item) : array => array_map('trim',explode(chr(58),$item)),$headers),1,0));
		if(isset($headers['connection'])):
			$close = (strtolower($headers['connection']) === 'close');
		else:
			$close = ($version === '1.0');
		endif;
		assert($close === false,'The http connection is closed !');
		if(isset($headers['content-length'])):
			$length = intval($headers['content-length']);
			if($length > 0):
				$body = $tcpClient->read($length);
			endif;
		endif;
		return $body;
	}
}

?>