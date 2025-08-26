<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

use Exception;

final class Security extends Exception {
	static public function checkG(int | string | \GMP $g,int | string | \GMP $p,bool $a_or_b = false) : void {
		/*
		Apart from the conditions on the Diffie-Hellman prime dh_prime and generator g, both sides are to check that g, g_a and g_b are greater than 1 and less than dh_prime - 1. We recommend checking that g_a and g_b are between 2^{2048-64} and dh_prime - 2^{2048-64} as well
		g > 1 , g_a > 1 , g_b > 1
		g < dh_prime ( p ) - 1 , g_a < dh_prime ( p ) - 1 , g_b < dh_prime ( p ) - 1
		*/
		if(gmp_cmp($g,1) < 1 || gmp_cmp($g,gmp_sub($p,1)) > 0):
			throw new Security('g a / g b is invalid !');
		endif;
		/*
		We recommend checking that g_a and g_b are between 2^{2048-64} and dh_prime - 2^{2048-64} as well
		2^{2048-64} < g_a < dh_prime ( p ) - 2^{2048-64}
		*/
		if($a_or_b === true):
			$maximum = gmp_init('1751908409537131537220509645351687597690304110853111572994449976845956819751541616602568796259317428464425605223064365804210081422215355425149431390635151955247955156636234741221447435733643262808668929902091770092492911737768377135426590363166295684370498604708288556044687341394398676292971255828404734517580702346564613427770683056761383955397564338690628093211465848244049196353703022640400205739093118270803778352768276670202698397214556629204420309965547056893233608758387329699097930255380715679250799950923553703740673620901978370802540218870279314810722790539899334271514365444369275682816');
			if(gmp_cmp($maximum,$g) > 0 || gmp_cmp($g,gmp_sub($p,$maximum)) > 0):
				throw new Security('g a / g b is invalid !');
			endif;
		endif;
	}
}

?>