<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Crypto;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Tools;

use Tak\Liveproto\Enums\ProtocolType;

use phpseclib3\Crypt\AES;

final class Obfuscation {
	private AES $encryptor;
	private AES $decryptor;
	public readonly string $init;

	public function __construct(public ProtocolType $protocol,public int $dc_id,public bool $test_mode = false,public bool $media_only = false,public ? string $secret = null){
		do {
			# init := (56 random bytes) + protocol + dc + (2 random bytes) #
			$init = random_bytes(56).$protocol->toBytes().Helper::pack('v',($media_only ? -1 : +1) * (abs($dc_id) + ($test_mode ? 10000 : 0))).random_bytes(2);
			$first_int = Helper::unpack('V',substr($init,0,4));
			$second_int = Helper::unpack('V',substr($init,4,4));
		} while(in_array($first_int,[0x44414548,0x54534f50,0x20544547,0x4954504f,0xdddddddd,0xeeeeeeee,0x02010316],true) or substr($init,0,1) === chr(0xef) or $second_int === 0x00000000);
		$encryptKey = substr($init,8,32);
		$encryptIV = substr($init,40,16);
		$reversed = strrev($init);
		$decryptKey = substr($reversed,8,32);
		$decryptIV = substr($reversed,40,16);
		$keyRev = substr($reversed,8,32);
		if(is_string($secret)):
			$secret = self::truncate(self::fromLink($secret));
			$encryptKey = hash('sha256',$encryptKey.$secret,true);
			$decryptKey = hash('sha256',$decryptKey.$secret,true);
		endif;
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
	}
	public function encrypt(string $plaintext) : string | false {
		return $this->encryptor->encrypt($plaintext);
	}
	public function decrypt(string $ciphertext) : string | false {
		return $this->decryptor->encrypt($ciphertext);
	}
	static public function truncate(string $binary) : string {
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
	static public function fromLink(string $secret) : string {
		if(ctype_xdigit($secret)):
			return hex2bin($secret);
		else:
			$bytes = Tools::base64_url_decode($secret);
			if(strlen($bytes) > 17 and strcasecmp($secret,'ee') === 1):
				return chr(0xee).substr($bytes,1);
			else:
				return $bytes;
			endif;
		endif;
	}
	static public function toLink(string $secret) : string {
		if(self::emulateTls($secret)):
			return Tools::base64_url_encode($secret);
		else:
			return bin2hex($secret);
		endif;
	}
	static public function emulateTls(string $secret) : bool {
		return boolval(strlen($secret) >= 17 and substr($secret,0,1) === chr(0xee));
	}
}

?>