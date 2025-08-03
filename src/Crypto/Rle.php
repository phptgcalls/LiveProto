<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Crypto;

abstract class Rle {
	static public function decode(string $string) : string {
		$new = strval(null);
		$last = strval(null);
		$null = chr(0);
		foreach(str_split($string) as $cur):
			if($last === $null):
				$new .= str_repeat($last,ord($cur));
				$last = strval(null);
			else:
				$new .= $last;
				$last = $cur;
			endif;
		endforeach;
		return strval($new.$last);
	}
	static public function encode(string $string) : string {
		$new = strval(null);
		$count = 0;
		$null = chr(0);
		foreach(str_split($string) as $cur):
			if($cur === $null):
				$count++;
			else:
				if ($count > 0):
					$new .= $null.chr($count);
					$count = 0;
				endif;
				$new .= $cur;
			endif;
		endforeach;
		if($count > 0) $new .= $null.chr($count);
		return $new;
	}
}

?>