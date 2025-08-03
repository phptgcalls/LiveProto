<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Utils\Tools;

use Tak\Liveproto\Utils\Errors;

use Tak\Liveproto\Crypto\Password;

use Tak\Liveproto\Enums\Authentication;

trait Auth {
	public function send_code(string $phone_number,mixed ...$settings) : object {
		try {
			$settings += ['logout_tokens'=>$this->load->logout_tokens];
			$result = $this->auth->sendCode(phone_number : $phone_number,settings : $this->codeSettings(...$settings),api_id : $this->load->api_id,api_hash : $this->load->api_hash);
			$this->load->phonenumber = $phone_number;
			if($result instanceof \Tak\Liveproto\Tl\Types\Auth\SentCode):
				if(empty($result->phone_code_hash) and isset($this->load->phonecodehash)):
					$result = $this->resend_code();
				endif;
				$this->load->phonecodehash = $result->phone_code_hash;
				$this->load->step = Authentication::NEEDCODE;
			elseif($result instanceof \Tak\Liveproto\Tl\Types\Auth\SentCodeSuccess):
				# $this->save_authorization($result->authorization);
				$this->load->step = Authentication::LOGIN;
			endif;
			return $result;
		} catch(Errors $error){
			if($error->getCode() == 303):
				$this->changeDC($error->getValue());
				return $this->send_code($phone_number,...$settings);
			else:
				throw $error;
			endif;
		}
	}
	public function sign_up(string $first_name,string $last_name) : object {
		$result = $this->auth->signUp(first_name : $first_name,last_name : $last_name,phone_number : $this->load->phonenumber,phone_code_hash : $this->load->phonecodehash);
		$this->save_authorization($result);
		$this->load->step = Authentication::LOGIN;
		return $result;
	}
	public function sign_in(string | int | null $code = null,#[\SensitiveParameter] ? string $password = null,#[\SensitiveParameter] ? string $token = null,? string $email = null) : object {
		if(is_null($code) === false):
			try {
				$result = $this->auth->signIn(phone_code : strval($code),email_verification : $email,phone_number : $this->load->phonenumber,phone_code_hash : $this->load->phonecodehash);
			} catch(\Throwable $error){
				if($error->getMessage() === 'SESSION_PASSWORD_NEEDED'):
					$this->load->step = Authentication::NEEDPASSWORD;
				endif;
				throw $error;
			}
		elseif(is_null($password) === false):
			$account = $this->account->getPassword();
			$checker = new Password();
			$input = $checker->srp($account,$password);
			$result = $this->auth->checkPassword(password : $input);
			$this->load->password = $password;
			$this->load->step = Authentication::LOGIN;
		elseif(is_null($token) === false):
			try {
				$result = $this->auth->importBotAuthorization(bot_auth_token : $token,api_id : $this->load->api_id,api_hash : $this->load->api_hash,flags : 0);
				$this->load->step = Authentication::LOGIN;
			} catch(\Throwable $error){
				if($error->getCode() == 303):
					$this->changeDC($error->getValue());
					$result = $this->sign_in(token : $token);
				else:
					throw $error;
				endif;
			}
		else:
			throw new \Exception('One of the code / password / token parameters must be entered in the sign in function !');
		endif;
		$this->save_authorization($result);
		return $result;
	}
	public function resend_code() : object {
		$result = $this->auth->resendCode(phone_number : $this->load->phonenumber,phone_code_hash : $this->load->phonecodehash);
		$this->load->step = Authentication::NEEDCODE;
		return $result;
	}
	public function cancel_code() : object {
		$result = $this->auth->cancelCode(phone_number : $this->load->phonenumber,phone_code_hash : $this->load->phonecodehash);
		$this->load->step = Authentication::NEEDAUTHENTICATION;
		return $result;
	}
	public function firebase_sms(? string $safety = null,? string $push = null) : object {
		if(is_null($safety) and is_null($push)):
			$safety = 'ysFoP5VLrJhIlp1ZgFfziiX5IEGhdgzdWJ5diTzjTMI=';
		endif;
		$result = $this->auth->requestFirebaseSms(phone_number : $this->load->phonenumber,phone_code_hash : $this->load->phonecodehash,safety_net_token : $safety,ios_push_secret : $push);
		return $result;
	}
	public function log_out() : object {
		$result = $this->auth->logOut();
		$this->save_authorization($result);
		$this->load->step = Authentication::NEEDAUTHENTICATION;
		return $result;
	}
	public function save_authorization(object $authorization) : void {
		if(isset($authorization->future_auth_token)):
			if(is_string($authorization->future_auth_token)):
				$this->load->logout_tokens []= $authorization->future_auth_token;
				$this->load->logout_tokens = array_slice($this->load->logout_tokens,-20);
			endif;
		endif;
	}
	public function login_token(array $except_ids = array()) : string {
		$loginToken = $this->auth->exportLoginToken(except_ids : $except_ids,api_id : $this->load->api_id,api_hash : $this->load->api_hash);
		$token = 'tg://login?token='.Tools::base64_url_encode($loginToken->token);
		return $token;
	}
	public function accept_token(string $token) : object {
		if(preg_match('~^tg:\/\/login\?token=(?<base64>[A-Za-z0-9_-]+)$~i',$token,$match)):
			$token = Tools::base64_url_decode($match['base64']);
			if($token === false) throw new \InvalidArgumentException('The base64 token of the input link is not valid !');
		endif;
		$result = $this->auth->acceptLoginToken(token : $token);
		return $result;
	}
	public function wait_token(array $except_ids = array(),int $timeout = 30) : void {
		$this->fetchUpdate(updates : array('updateLoginToken'),timeout : $timeout)->await();
		$loginToken = $this->auth->exportLoginToken(except_ids : $except_ids,api_id : $this->load->api_id,api_hash : $this->load->api_hash);
		if($loginToken instanceof \Tak\Liveproto\Tl\Types\Auth\LoginTokenMigrateTo):
			$this->changeDC($loginToken->dc_id);
			$loginToken = $this->auth->importLoginToken(token : $loginToken->token);
		endif;
		if($loginToken instanceof \Tak\Liveproto\Tl\Types\Auth\LoginTokenSuccess):
			$this->load->step = Authentication::LOGIN;
		endif;
	}
}

?>