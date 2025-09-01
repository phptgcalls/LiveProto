<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Errors\Security;

trait Utilities {
	public function getDhConfig() : object {
		$version = is_null($this->dhConfig) === false ? $this->dhConfig->version : 0;
		$getDhConfig = $this->messages->getDhConfig(version : $version,random_length : 0);
		if($getDhConfig instanceof \Tak\Liveproto\Tl\Types\Messages\DhConfig):
			$getDhConfig->p = strval(gmp_import($getDhConfig->p));
			Security::checkGoodPrime($getDhConfig->p,$getDhConfig->g);
			$this->dhConfig = $getDhConfig;
		elseif(is_null($this->dhConfig)):
			throw new \Exception('dh config not modified !');
		endif;
		return $this->dhConfig;
	}
}

?>