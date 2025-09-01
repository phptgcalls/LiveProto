<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Errors;

use Exception;

final class TransportError extends Exception {
	protected const MESSAGES = [
		403=>'Forbidden : Corresponds to HTTP 403 situations',
		404=>'Auth key not found : The specified auth_key_id cannot be found by the DC . Can happen during initial MTProto handshake or when MTProto fields are incorrect ( packet length mismatch , ... )',
		429=>'Transport flood : Too many transport connections from the same IP in a short time or container / service message limits exceeded',
		444=>'Invalid DC : Returned while creating an auth key , connecting to an MTProxy , or when an invalid DC ID is specified'
	];

	public function __construct(protected string $description,int $code = 0){
		$code = abs($code);
		$message = isset(self::MESSAGES[$code]) ? self::MESSAGES[$code] : 'UNKNOWN';
		parent::__construct($message,$code);
	}
	public function getDescription() : string {
		return $this->description;
	}
	public function __toString(){
		return $this->getMessage().chr(32).$this->getCode().chr(32).chr(58).chr(32).$this->getDescription();
	}
}

?>