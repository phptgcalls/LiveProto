<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Crypto;

readonly class Factorizator {
	static public function decompose(int $pq) : int {
		$y = gmp_random_range(1,gmp_sub($pq,1));
		$c = gmp_random_range(1,gmp_sub($pq,1));
		$g = $r = $q = gmp_init(1);
		while(gmp_cmp($g,1) == 0):
			$x = $y;
			$y = gmp_mod(gmp_add(gmp_powm($y,2,$pq),$c),$pq);
			for($i = 0;$i < gmp_intval($r);$i++):
				$y = gmp_mod(gmp_add(gmp_powm($y,2,$pq),$c),$pq);
			endfor;
			$k = gmp_init(0);
			while(gmp_cmp($k,$r) < 0 and gmp_cmp($g,1) == 0):
				for($i = 0; $i < gmp_intval(gmp_sub($r,$k)); $i++):
					$y = gmp_mod(gmp_add(gmp_mul($y,$y),$c),$pq);
					$q = gmp_mod(gmp_mul($q,gmp_abs(gmp_sub($x,$y))),$pq);
				endfor;
				$g = self::gcd($q,$pq);
				$k = gmp_add($k,$r);
			endwhile;
			$r = gmp_mul($r,2);
		endwhile;
		if(gmp_cmp($g,$pq) == 0):
			while(true):
				$y = gmp_mod(gmp_add(gmp_mul($y,$y),$c),$pq);
				$g = self::gcd(gmp_abs(gmp_sub($x,$y)),$pq);
				if(gmp_cmp($g,1) > 0) break;
			endwhile;
		endif;
		$p = gmp_div($pq,$g);
		return intval(min($p,$g));
	}
	static public function find(int $what) : int {
		$g = 0;
		for($i = 0;$i < 3;$i++):
			$q = (rand(0,127) & 15) + 17;
			$x = rand(0,1000000000) + 1;
			$y = $x;
			$limit = 1 << ($i + 18);
			for($j = 1;$j < $limit;$j++):
				$a = $b = $c = $x;
				$x = self::mul($a,$b,$c,$what);
				$z = ($x < $y) ? $y - $x : $x - $y;
				$g = self::gcd($z,$what);
				if($g != 1) break;
				if(($j & ($j - 1)) == 0) $y = $x;
			endfor;
			if($g > 1) break;
		endfor;
		$p = $what / $g;
		return intval(min($p,$g));
	}
	static public function mul(int $a,int $b,int $c,int $what) : int {
		while($b != 0):
			if(($b & 1) != 0):
				$c += $a;
				if($c >= $what) $c -= $what;
			endif;
			$a += $a;
			if($a >= $what) $a -= $what;
			$b >>= 1;
		endwhile;
		return $c;
	}
	static public function gcd(int | string | \GMP $a,int | string | \GMP $b) : mixed {
		if(extension_loaded('gmp')):
			return gmp_gcd($a,$b);
		else:
			while($a != 0 and $b != 0):
				while(($b & 1) == 0):
					$b >>= 1;
				endwhile;
				while(($a & 1) == 0):
					$a >>= 1;
				endwhile;
				if($a > $b):
					$a -= $b;
				else:
					$b -= $a;
				endif;
			endwhile;
			return ($b == 0) ? $a : $b;
		endif;
	}
	static public function factorize(int $pq) : array {
		if(is_callable('tg_factorize')):
			return array_values(tg_factorize($pq));
		elseif(extension_loaded('gmp')):
			$divisor = self::decompose($pq);
		else:
			$divisor = self::find($pq);
		endif;
		return [$divisor,$pq / $divisor];
	}
}

?>