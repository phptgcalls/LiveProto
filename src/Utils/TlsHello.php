<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

use phpseclib3\Crypt\EC\Curves\Curve25519;

use phpseclib3\Math\BigInteger;

final class TlsHello {
	private const MAX_GREASE = 8;
	public const OPS = [
		['type'=>'string','data'=>"\x16\x03\x01\x02\x00\x01\x00\x01\xfc\x03\x03"],
		['type'=>'zero','len'=>32],
		['type'=>'string','data'=>"\x20"],
		['type'=>'random','len'=>32],
		['type'=>'string','data'=>"\x00\x20"],
		['type'=>'grease','seed'=>0],
		['type'=>'string','data'=>"\x13\x01\x13\x02\x13\x03\xc0\x2b\xc0\x2f\xc0\x2c\xc0\x30\xcc\xa9\xcc\xa8\xc0\x13\xc0\x14\x00\x9c\x00\x9d\x00\x2f\x00\x35\x01\x00\x01\x93"],
		['type'=>'grease','seed'=>2],
		['type'=>'string','data'=>"\x00\x00"],
		['type'=>'permutation','entities'=>[
			[
				['type'=>'string','data'=>"\x00\x00"],
				['type'=>'begin'],
				['type'=>'begin'],
				['type'=>'string','data'=>"\x00"],
				['type'=>'begin'],
				['type'=>'domain'],
				['type'=>'end'],
				['type'=>'end'],
				['type'=>'end'],
			],
			[
				['type'=>'string','data'=>"\x00\x05\x00\x05\x01\x00\x00\x00\x00"],
			],
			[
				['type'=>'string','data'=>"\x00\x0a\x00\x0a\x00\x08"],
				['type'=>'grease','seed'=>4],
				['type'=>'string','data'=>"\x00\x1d\x00\x17\x00\x18"],
			],
			[
				['type'=>'string','data'=>"\x00\x0b\x00\x02\x01\x00"],
			],
			[
				['type'=>'string','data'=>"\x00\x0d\x00\x12\x00\x10\x04\x03\x08\x04\x04\x01\x05\x03\x08\x05\x05\x01\x08\x06\x06\x01"],
			],
			[
				['type'=>'string','data'=>"\x00\x10\x00\x0e\x00\x0c\x02\x68\x32\x08\x68\x74\x74\x70\x2f\x31\x2e\x31"],
			],
			[['type'=>'string','data'=>"\x00\x12\x00\x00"]],
			[['type'=>'string','data'=>"\x00\x17\x00\x00"]],
			[['type'=>'string','data'=>"\x00\x1b\x00\x03\x02\x00\x02"]],
			[['type'=>'string','data'=>"\x00\x23\x00\x00"]],
			[['type'=>'string','data'=>"\x00\x2b\x00\x07\x06"],['type'=>'grease','seed'=>6],['type'=>'string','data'=>"\x03\x04\x03\x03"]],
			[['type'=>'string','data'=>"\x00\x2d\x00\x02\x01\x01"]],
			[
				['type'=>'string','data'=>"\x00\x33\x00\x2b\x00\x29"],
				['type'=>'grease','seed'=>4],
				['type'=>'string','data'=>"\x00\x01\x00\x00\x1d\x00\x20"],
				['type'=>'K'],
			],
			[['type'=>'string','data'=>"\x44\x69\x00\x05\x00\x03\x02\x68\x32"]],
			[['type'=>'string','data'=>"\xff\x01\x00\x01\x00"]],
		]],
		['type'=>'grease','seed'=>3],
		['type'=>'string','data'=>"\x00\x01\x00\x00\x15"]
	];

	private array $grease = array();
	private array $heap = array();
	public string $buffer;

	public function __construct(private ? string $domain = null){
		$r = random_bytes(self::MAX_GREASE);
		for($i = 0; $i < self::MAX_GREASE; $i++):
			$v = ord($r[$i]);
			$v = ($v & 0xF0) + 0x0A;
			if($i % 2 === 1 and $v === $this->grease[$i - 1]):
				$v ^= 0x10;
			endif;
			$this->grease[$i] = $v;
		endfor;
		$this->buffer = strval(null);
	}
	public function writeToBuffer(array $ops = self::OPS) : string {
		foreach($ops as $op){
			$this->buffer .= $this->writeOp($op);
		}
		return $this->buffer;
	}
	public function writePadding(string $data) : string {
		$length = strlen($data);
		if($length > 515):
			return strval(null);
		else:
			$size = 515 - $length;
			$prefix = chr(($size >> 8) & 0xFF).chr($size & 0xFF);
			$padding = str_repeat(chr(0),$size);
			return $data.$prefix.$padding;
		endif;
	}
	private function writeOp(array $op) : string {
		$type = $op['type'];
		switch($type):
			case 'string':
				return $op['data'];
			case 'random':
				return random_bytes($op['len']);
			case 'K':
				return self::generatePublicKey();
			case 'zero':
				return str_repeat(chr(0),$op['len']);
			case 'domain':
				return substr(strval($this->domain),0,253);
			case 'grease':
				$seed = $op['seed'];
				$v = $this->grease[$seed % count($this->grease)];
				return chr($v).chr($v);
			case 'begin':
				$this->heap []= strlen($this->buffer);
				return pack('C2',0,0);
			case 'end':
				$offset = intval(array_pop($this->heap));
				$size = strlen($this->buffer) - ($offset + 2);
				$this->buffer[$offset] = chr(($size >> 8) & 0xFF);
				$this->buffer[$offset + 1] = chr($size & 0xFF);
				return strval(null);
			case 'permutation':
				$entities = $op['entities'];
				shuffle($entities);
				foreach($entities as $sub):
					$this->writeToBuffer($sub);
				endforeach;
				return strval(null);
			default:
				return strval(null);
		endswitch;
	}
	static private function generatePublicKey() : string {
		$curve = new Curve25519();
		$curve->setCoefficients(new BigInteger('486662'));
		$scalarBytes = random_bytes(0x20);
		$scalar = new BigInteger($scalarBytes,0x100);
		$basePoint = $curve->getBasePoint();
		$resultPoint = $curve->multiplyPoint($basePoint,$scalar);
		$affine = $curve->convertToAffine($resultPoint);
		$xInt = $affine[false];
		$xBytes = $xInt->toBytes();
		return str_pad($xBytes,0x20,chr(0),STR_PAD_LEFT);
	}
}

?>