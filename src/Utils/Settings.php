<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

final class Settings {
	protected array $data;

	public function __get(string $property) : mixed {
		$index = strtolower($property);
		$value = isset($this->data[$index]) ? $this->data[$index] : null;
		switch($index):
			case 'apiid':
				if(is_int($value) and $value <= 0) throw new \Exception('In the Settings , a valid value for the API ID has not been set');
				if(is_int($value) === false) $value = 21724;
				break;
			case 'apihash':
				if(is_string($value) and strlen($value) !== 32) throw new \Exception('In the Settings , a valid value for the API HASH has not been set');
				if(is_string($value) === false) $value = '3e0cb5efcd52300aec5994fdfc5bdc16';
				break;
			case 'devicemodel':
				if(is_string($value) === false) $value = php_uname('s');
				break;
			case 'systemversion':
				if(is_string($value) === false) $value = php_uname('r');
				break;
			case 'appversion':
				if(is_string($value) === false) $value = '0.26.8.1721-universal';
				break;
			case 'systemlangcode':
				if(is_string($value) === false) $value = (extension_loaded('intl') ? locale_get_primary_language(locale_get_default()).'-'.locale_get_region(locale_get_default()) : 'en-US');
				break;
			case 'langpack':
				if(is_string($value) === false) $value = 'android';
				break;
			case 'langcode':
				if(is_string($value) === false) $value = (extension_loaded('intl') ? locale_get_primary_language(locale_get_default()) : 'en');
				break;
			case 'floodsleepthreshold':
				if(is_int($value) === false) $value = 120;
				break;
			case 'receiveupdates':
				if(is_bool($value) === false) $value = true;
				break;
			case 'ipv6':
				if(is_bool($value) === false) $value = false;
				break;
			case 'takeout':
				if(is_array($value) === false) $value = false;
				break;
			case 'protocol':
				if(is_a($value,'Tak\\Liveproto\\Enums\\ProtocolType') === false) $value = null;
				break;
			case 'proxy':
				if(is_array($value) and array_is_list($value) === false):
					if(isset($value['type']) === false) throw new \Exception('The type parameter value must be set in the proxy');
					if(isset($value['address']) === false) throw new \Exception('The address parameter value must be set in the proxy');
					if(isset($value['type']) and is_string($value['type']) === false) throw new \Exception('The value of the type parameter in the proxy must be of string type');
					if(isset($value['address']) and is_string($value['address']) === false) throw new \Exception('The value of the address parameter in the proxy must be of string type');
					if(isset($value['username']) === false or is_string($value['username']) === false):
						$value['username'] = null;
					endif;
					if(isset($value['password']) === false or is_string($value['password']) === false):
						$value['password'] = null;
					endif;
					if(isset($value['secret']) === false or is_string($value['secret']) === false):
						$value['secret'] = null;
					endif;
				else:
					$value = null;
				endif;
				break;
			case 'params':
				if(is_object($value) === false) $value = null;
				break;
		endswitch;
		return $value;
	}
	public function __set(string $name,mixed $value) : void {
		$this->data[strtolower($name)] = $value;
	}
	public function __unset(string $name) : void {
		unset($this->data[strtolower($name)]);
	}
	public function __isset(string $name) : bool {
		return isset($this->data[strtolower($name)]);
	}
	public function __call(string $method,array $arguments) : mixed {
		if(preg_match('~^set([a-z0-9]+)$~i',$method,$match)):
			$name = $match[true];
			$this->$name = (array_is_list($arguments) and count($arguments) === 1) ? $arguments[false] : $arguments;
			return $this;
		elseif(preg_match('~^get([a-z0-9]+)$~i',$method,$match)):
			$name = $match[true];
			return $this->$name;
		else:
			throw new \Exception('Call to undefined function '.$method.'()');
		endif;
	}
	public function __debugInfo() : array {
		return $this->data;
	}
}

?>