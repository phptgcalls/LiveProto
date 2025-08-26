<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

abstract class Helper {
	static public function generateRandomLong() : int {
		$long = random_int(PHP_INT_MIN,PHP_INT_MAX);
		return $long === 0 ? call_user_func(__METHOD__) : $long;
	}
	static public function generateRandomLargeInt(int $bits = 0x40) : string {
		$bytes = intdiv($bits,8);
		return strval(gmp_import(random_bytes($bytes),$bytes));
	}
	static public function generateRandomString(int $length = 0x10) : string {
		return substr(str_shuffle(implode([...range('A','Z'),...range('a','z'),...range(0,9)])),-abs($length));
	}
	static public function getByteArray(object | string | int $integer) : string {
		if(is_object($integer)):
			return gmp_export(strval($integer));
		elseif(is_string($integer) || is_int($integer)):
			return gmp_export(gmp_init($integer,0xa)); // base‑10 //
		endif;
		/*
		Why two different export ? because... gmp_export('010') !== gmp_export(gmp_init('010',0xa));
		elseif(is_int($integer)):
			$hex = dechex($integer);
			$dec = (strlen($hex) % 0x2 == 0x0) ? $hex : strval(0x0).$hex;
			return pack('C*',...array_map('hexdec',str_split($dec,0x2)));
		endif;
		*/
	}
	/* V2 : https://core.telegram.org/mtproto/description#defining-aes-key-and-initialization-vector */
	static public function keyCalculate(string $authKey,string $msgKey,bool $client) : array {
		$x = $client ? 0x0 : 0x8;
		$a = hash('sha256',$msgKey.substr($authKey,$x,0x24),true);
		$b = hash('sha256',substr($authKey,0x28 + $x,0x24).$msgKey,true);
		$key = substr($a,0x0,0x8).substr($b,0x8,0x10).substr($a,0x18,0x8);
		$iv = substr($b,0x0,0x8).substr($a,0x8,0x10).substr($b,0x18,0x8);
		return [$key,$iv];
	}
	/* V1 : https://core.telegram.org/mtproto/description_v1#defining-aes-key-and-initialization-vector */
	static public function aesCalculate(string $authKey,string $msgKey,bool $client) : array {
		$x = $client ? 0x0 : 0x8;
		$a = sha1($msgKey.substr($authKey,$x,0x20),true);
		$b = sha1(substr($authKey,$x + 0x20,0x10).$msgKey.substr($authKey,$x + 0x30,0x10),true);
		$c = sha1(substr($authKey,$x + 0x40,0x20).$msgKey,true);
		$d = sha1($msgKey.substr($authKey,$x + 0x60,0x20),true);
		$key = substr($a,0x0,0x8).substr($b,0x8,0xc).substr($c,0x4,0xc);
		$iv = substr($a,0x8,0xc).substr($b,0x0,0x8).substr($c,0x10,0x4).substr($d,0x0,0x8);
		return [$key,$iv];
	}
	static public function generateKeyNonces(string $serverNonce,string $newNonce) : array {
		$hash1 = sha1($newNonce.$serverNonce,true);
		$hash2 = sha1($serverNonce.$newNonce,true);
		$hash3 = sha1($newNonce.$newNonce,true);
		$key = $hash1.substr($hash2,0x0,0xc);
		$iv = substr($hash2,0xc,0x8).$hash3.substr($newNonce,0x0,0x4);
		return [$key,$iv];
	}
}

?>