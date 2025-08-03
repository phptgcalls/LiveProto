<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Crypto;

use Tak\Liveproto\Utils\Tools;

use Tak\Liveproto\Enums\ProtocolType;

use phpseclib3\Crypt\AES;

final class Obfuscation {
	private AES $encryptor;
	private AES $decryptor;
	public readonly string $init;

	public function __construct(public ProtocolType $protocol,public int $dcid,public bool $testmode = false,public bool $mediaDC = false,public ? string $secret = null){
		$to_int = fn(string $bytes) : int => gmp_intval(gmp_import(strrev($bytes)));
		do {
			# init := (56 random bytes) + protocol + dc + (2 random bytes) #
			$init = random_bytes(56).$protocol->toBytes().pack('v',($mediaDC ? -1 : +1) * (abs($dcid) + ($testmode ? 1000 : 0))).random_bytes(2);
			$first_int = $to_int(substr($init,0,4));
			$second_int = $to_int(substr($init,4,4));
		} while(in_array($first_int,[0x44414548,0x54534f50,0x20544547,0x4954504f,0xdddddddd,0xeeeeeeee,0x02010316],true) or substr($init,0,1) === chr(0xef) or $second_int === 0x00000000);
		$encryptKey = substr($init,8,32);
		$encryptIV = substr($init,40,16);
		$reversed = strrev($init);
		$decryptKey = substr($reversed,8,32);
		$decryptIV = substr($reversed,40,16);
		$keyRev = substr($reversed,8,32);
		if(is_string($secret)):
			$secret = $this->truncate($this->fromLink($secret));
			$encryptKey = hash('sha256',$encryptKey.$secret,true);
			$decryptKey = hash('sha256',$decryptKey.$secret,true);
		endif;
		/*
		$encryptKey = base64_decode('j3+cF+3ZO4Drct9vGeKim0W9qDHqOF5lIUY2I7B2qOY=');
		$encryptIV = base64_decode('AHCdL2tdE+UJAExGhTBe5A==');
		$decryptKey = base64_decode('5F4whUZMAAnlE11rL51wAOaodrAjNkYhZV446jGovUU=');
		$decryptIV = base64_decode('m6LiGW/fcuuAO9ntF5x/jw==');
		*/
		$this->encryptor = new AES('ctr');
		$this->encryptor->enableContinuousBuffer();
		$this->encryptor->setKey($encryptKey);
		$this->encryptor->setIV($encryptIV);
		$this->decryptor = new AES('ctr');
		$this->decryptor->enableContinuousBuffer();
		$this->decryptor->setKey($decryptKey);
		$this->decryptor->setIV($decryptIV);
		$encrypted = $this->encrypt($init);
		$this->init = substr_replace($init,substr($encrypted,56,8),56,8);
		/*
		$this->init = base64_decode('q9wMP/3Ke9mPf5wX7dk7gOty328Z4qKbRb2oMeo4XmUhRjYjsHao5gBwnS9rXRPlCQBMRoUwXuQ1teMWYqJwbw==');
		*/
	}
	public function truncate(string $binary) : string {
		if(strlen($binary) === 16):
			return $binary;
		elseif(strlen($binary) === 17 and substr($binary,0,1) === chr(0xdd)):
			return substr($binary,1,16);
		elseif(strlen($binary) >= 18 and substr($binary,0,1) === chr(0xee)):
			return substr($binary,1,16);
		else:
			throw new \InvalidArgumentException('Your binary secret is invalid !');
		endif;
	}
	public function fromLink(string $secret) : string {
		return ctype_xdigit($secret) ? hex2bin($secret) : Tools::base64_url_decode($secret);
	}
	public function toLink(string $secret) : string {
		if($this->emulateTls($secret)):
			return Tools::base64_url_encode($secret);
		else:
			return bin2hex($secret);
		endif;
	}
	public function emulateTls(string $secret) : bool {
		return boolval(strlen($secret) >= 17 and substr($secret,0,1) === chr(0xee));
	}
	public function encrypt(string $plaintext) : string | false {
		return $this->encryptor->encrypt($plaintext);
	}
	public function decrypt(string $ciphertext) : string | false {
		return $this->decryptor->encrypt($ciphertext);
	}
}

?>