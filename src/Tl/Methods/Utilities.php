<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

trait Utilities {
	public function getDhConfig() : object {
		static $dhConfig;
		$version = is_null($dhConfig) === false ? $dhConfig->version : 0;
		$getDhConfig = $this->messages->getDhConfig(version : $version,random_length : 0);
		if($getDhConfig instanceof \Tak\Liveproto\Tl\Types\Messages\DhConfig):
			$getDhConfig->p = strval(gmp_import($getDhConfig->p));
			$dhConfig = $getDhConfig;
		elseif(is_null($dhConfig)):
			throw new \Exception('dh config not modified !');
		endif;
		return $dhConfig;
	}
}

?>