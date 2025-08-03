<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

abstract class StringTools {
	static public function offset(string | array $text,int $byteOffset) : int {
		$text = is_array($text) ? array_values($text) : $text;
		$length = is_array($text) ? count($text) : strlen($text);
		$offset = 0;
		for($i = 0;$i < $length;$i++):
			$byte = ord($text[$i]);
			if(($byte & 0xc0) != 0x80):
				if($i >= $byteOffset):
					return $offset;
				else:
					$offset += intval($byte >= 0xf0) + 0x1;
				endif;
			endif;
		endfor;
		return $offset;
	}
	static public function length(string | array $text) : int {
		$text = is_array($text) ? array_values($text) : $text;
		$length = is_array($text) ? count($text) : strlen($text);
		$byteLength = 0;
		for($i = 0;$i < $length;$i++):
			$byte = ord($text[$i]);
			if(($byte & 0xc0) != 0x80):
				$byteLength += intval($byte >= 0xf0) + 0x1;
			endif;
		endfor;
		return $byteLength;
	}
	static public function substr(string | array $text,int $offset,? int $length = null) : string {
		$text = is_array($text) ? implode($text) : $text;
		return mb_convert_encoding(substr(mb_convert_encoding($text,'UTF-16'),$offset << 0x1,is_int($length) ? ($length << 0x1) : null),'UTF-8','UTF-16');
	}
	static public function strsplit(string | array $text,int $length) : array {
		$text = is_array($text) ? implode($text) : $text;
		return array_map(fn(string $chunk) : string => mb_convert_encoding($chunk,'UTF-8','UTF-16'),str_split(mb_convert_encoding($text,'UTF-16'),$length << 0x1));
	}
}

?>