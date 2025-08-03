<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

use Tak\Liveproto\Tl\All;

use Stringable;

final class Binary implements Stringable {
	public readonly bool $repeating;
	public readonly int $id;
	private int $position;
	private int $length;
	private string $data;
	private array $previousread;
	private array $previouswrite;

	public function __construct(bool $repeating = false){
		$this->repeating = $repeating;
		$this->id = intval(microtime(true) * 1000);
		$this->position = 0;
		$this->length = 0;
		$this->data = strval(null);
		$this->previousread = array();
		$this->previouswrite = array();
	}
	public function readByte() : mixed {
		return unpack('C',$this->read(1))[true];
	}
	public function readInt() : mixed {
		return unpack('V',$this->read(4))[true];
	}
	public function readLong() : mixed {
		return unpack('P',$this->read(8))[true];
	}
	public function readFloat() : mixed {
		return unpack('f',$this->read(4))[true];
	}
	public function readDouble() : mixed {
		return unpack('d',$this->read(8))[true];
	}
	public function readLargeInt(int $bits = 0x40) : mixed {
		$bytes = intdiv($bits,8);
		return strval(gmp_import($this->read($bytes),$bytes));
	}
	public function tgreadBytes() : string {
		$firstByte = $this->readByte();
		if($firstByte == 0xfe):
			$length = $this->readByte() | ($this->readByte() << 8) | ($this->readByte() << 16);
			$padding = $length % 4;
		else:
			$length = $firstByte;
			$padding = ($length + 1) % 4;
		endif;
		$data = $this->read($length);
		if($padding > 0) $this->read(4 - $padding);
		return $data;
	}
	public function tgreadBool(bool $undo = false) : bool {
		if($undo) $this->undo();
		$object = new \Tak\Liveproto\Tl\Types\Other\BoolFalse;
		$request = $object->request();
		return $this->readInt() !== $request->readInt();
	}
	public function tgreadVector(string $type,bool $undo = false) : array {
		if($undo) $this->undo();
		$vector = array();
		$vectorid = $this->readInt();
		$length = $this->readInt();
		for($i = 0;$i < $length;$i++):
			$vector[] = match($type){
				'int' => $this->readInt(),
				'int128' => $this->readLargeInt(128),
				'int256' => $this->readLargeInt(256),
				'int512' => $this->readLargeInt(512),
				'long' => $this->readLong(),
				'double' => $this->readDouble(),
				'string' => $this->tgreadBytes(),
				'bytes' => $this->tgreadBytes(),
				'bool' => $this->tgreadBool(),
				default => $this->tgreadObject()
			};
		endfor;
		return $vector;
	}
	public function tgreadObject(bool $undo = false) : object {
		if($undo) $this->undo();
		$constructorId = $this->readInt();
		$response = match(intval($constructorId)){
			0xbc799737 , 0x997275b5 , 0x3fedd339 => (object) ['bool'=>$this->tgreadBool(...)],
			0x1cb5c415 => (object) ['vector'=>$this->tgreadVector(...)],
			default => All::getConstructor($constructorId)->response($this)
		};
		return $response;
	}
	public function read(int $length = PHP_INT_MAX) : mixed {
		$read = substr($this->data,0x0,$length);
		$this->data = substr($this->data,$length);
		$this->position += strlen($read);
		$this->previousread []= $read;
		if(empty($this->data) and $this->repeating):
			$this->data = implode($this->previousread);
			$this->position = 0;
			$this->previousread = array();
		endif;
		return $read;
	}
	public function redo(int $limit = PHP_INT_MAX) : mixed {
		$write = substr(strval(array_pop($this->previouswrite)),0x0,$limit);
		$this->data = substr($this->data,0x0,-strlen($write));
		$this->length -= strlen($write);
		return $this;
	}
	public function tellLength() : int {
		return $this->length;
	}
	public function tellPosition() : int {
		return $this->position;
	}
	public function writeByte(mixed $value) : self {
		return $this->write(pack('C',$value));
	}
	public function writeInt(mixed $value) : self {
		return $this->write(pack('V',$value));
	}
	public function writeLong(mixed $value) : self {
		return $this->write(pack('P',$value));
	}
	public function writeFloat(mixed $value) : self {
		return $this->write(pack('f',$value));
	}
	public function writeDouble(mixed $value) : self {
		return $this->write(pack('d',$value));
	}
	public function writeLargeInt(mixed $value,int $bits = 0x40) : self {
		$bytes = intdiv($bits,8);
		return $this->write(gmp_export($value,$bytes));
	}
	public function tgwriteBytes(string $data) : self {
		$length = strlen($data);
		if($length < 0xfe):
			$padding = ($length + 1) % 4;
			if($padding != 0) $padding = (4 - $padding);
			$this->write(pack('C*',$length));
			$this->write($data);
		else:
			$padding = $length % 4;
			if($padding != 0) $padding = 4 - $padding;
			$this->write(pack('C*',0xfe));
			$this->write(pack('C*',$length % 0x100));
			$this->write(pack('C*',($length >> 8) % 0x100));
			$this->write(pack('C*',($length >> 16) % 0x100));
			$this->write($data);
		endif;
		return $this->write(str_repeat(chr(0),$padding));
	}
	public function tgwriteBool(bool $boolean,bool $redo = false) : self {
		if($redo) $this->redo();
		if($boolean):
			$object = new \Tak\Liveproto\Tl\Types\Other\BoolTrue;
			$request = $object->request();
		else:
			$object = new \Tak\Liveproto\Tl\Types\Other\BoolFalse;
			$request = $object->request();
		endif;
		return $this->write($request->read());
	}
	public function tgwriteVector(array $vectors,string $type,bool $redo = false) : self {
		if($redo) $this->redo();
		$this->writeInt(0x1cb5c415);
		$this->writeInt(count($vectors));
		foreach($vectors as $vector):
			match($type){
				'int' => $this->writeInt($vector),
				'int128' => $this->writeLargeInt($vector,128),
				'int256' => $this->writeLargeInt($vector,256),
				'int512' => $this->writeLargeInt($vector,512),
				'long' => $this->writeLong($vector),
				'double' => $this->writeDouble($vector),
				'string' => $this->tgwriteBytes($vector),
				'bytes' => $this->tgwriteBytes($vector),
				'bool' => $this->tgwriteBool($vector),
				default => $this->write($vector->read())
			};
		endforeach;
		return $this;
	}
	public function write(string $data) : self {
		$this->data .= $data;
		$this->length += strlen($data);
		$this->previouswrite []= $data;
		return $this;
	}
	public function undo(int $limit = PHP_INT_MAX) : self {
		$write = substr(strval(array_pop($this->previousread)),0x0,$limit);
		if(empty($write) === false):
			$this->position -= strlen($write);
			$this->data = $write.$this->data;
			$this->previouswrite []= $write;
			array_pop($this->previouswrite);
		endif;
		return $this;
	}
	public function setLength(int $length) : self {
		$this->length = $length;
		return $this;
	}
	public function setPosition(int $position) : self {
		if($position - $this->position > 0) $this->read($position - $this->position);
		return $this;
	}
	public function __debugInfo() : array {
		$class = strval($this);
		return array(
			'class'=>class_exists($class) ? new $class : null,
			'bytes'=>$this->data
		);
	}
	public function __toString() : string {
		try {
			$constructorId = $this->readInt();
			$this->undo();
			return All::getConstructor($constructorId)->getClass();
		} catch(\Throwable){
			return strval($constructorId);
		}
	}
}

?>