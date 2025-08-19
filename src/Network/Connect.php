<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network;

use Tak\Liveproto\Network\PlainSender;

use Tak\Liveproto\Crypto\Factorizator;

use Tak\Liveproto\Crypto\Rsa;

use Tak\Liveproto\Crypto\Aes;

use Tak\Liveproto\Crypto\AuthKey;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Binary;

use Tak\Liveproto\Utils\Logging;

final class Connect {
	public PlainSender $sender;

	public function __construct(object $transport,object $session){
		$this->sender = new PlainSender($transport,$session);
	}
	public function authentication(int $dc_id = 0,bool $test_mode = false,bool $media_only = false,int $expires_in = 0) : array {
		$sender = (object) $this->sender;
		$nonce = strval(gmp_import(random_bytes(0x10)));
		$reqPQ = $sender(new \Tak\Liveproto\Tl\Functions\Other\ReqPqMulti,nonce : $nonce);
		assert($reqPQ->nonce === $nonce,'Nonce from server is not equal to nonce !');
		$serverNonce = $reqPQ->server_nonce;
		$pq = intval(gmp_import($reqPQ->pq));
		$newNonce = strval(gmp_import(random_bytes(0x20)));
		list($p,$q) = Factorizator::factorize($pq);
		Logging::log('Factorizator','P = '.$p.' & Q = '.$q,0);
		$pqInnerParams = array(
			'pq'=>Helper::getByteArray($pq),
			'p'=>Helper::getByteArray(min($p,$q)),
			'q'=>Helper::getByteArray(max($p,$q)),
			'nonce'=>$nonce,
			'server_nonce'=>$serverNonce,
			'new_nonce'=>$newNonce
		);
		$expires_at = intval($expires_in > 0 ? time() + $expires_in : 0);
		$dc = ($media_only ? -1 : +1) * (abs($dc_id) + ($test_mode ? 1000 : 0));
		if($expires_at === 0):
			if($dc_id === 0):
				$pqInnerData = new \Tak\Liveproto\Tl\Types\Other\PQInnerData($pqInnerParams);
			else:
				$pqInnerData = new \Tak\Liveproto\Tl\Types\Other\PQInnerDataDc($pqInnerParams + array('dc'=>$dc));
			endif;
		else:
			if($dc_id === 0):
				$pqInnerData = new \Tak\Liveproto\Tl\Types\Other\PQInnerDataTemp($pqInnerParams + array('expires_in'=>$expires_in));
			else:
				$pqInnerData = new \Tak\Liveproto\Tl\Types\Other\PQInnerDataTempDc($pqInnerParams + array('dc'=>$dc,'expires_in'=>$expires_in));
			endif;
		endif;
		$data = $pqInnerData->read();
		Logging::log('Fingerprints',implode(chr(0x20).chr(0x2c).chr(0x20),$reqPQ->server_public_key_fingerprints),0);
		foreach($reqPQ->server_public_key_fingerprints as $fingerprint):
			$cipher = Rsa::find($fingerprint,$data);
			if(is_null($cipher) === false):
				Logging::log('Fingerprint',$fingerprint,0);
				break;
			endif;
		endforeach;
		if(is_null($cipher)):
			throw new \RuntimeException('Fingerprint not found !');
		endif;
		$reqDH = $sender(new \Tak\Liveproto\Tl\Functions\Other\ReqDHParams,nonce : $nonce,server_nonce : $serverNonce,p : Helper::getByteArray(min($p,$q)),q : Helper::getByteArray(max($p,$q)),public_key_fingerprint : $fingerprint,encrypted_data : $cipher);
		assert($reqDH->nonce === $nonce,'Nonce from server is not equal to nonce !');
		assert($reqDH->server_nonce === $serverNonce,'Server nonce from server is not equal to server nonce !');
		if($reqDH instanceof \Tak\Liveproto\Tl\Types\Other\ServerDHParamsFail):
			throw new \Exception('Server DH Params Fail !');
		endif;
		list($key,$iv) = Helper::generateKeyNonces(gmp_export($serverNonce,0x10),gmp_export($newNonce,0x20));
		$decrypted = Aes::decrypt($reqDH->encrypted_answer,$key,$iv);
		// answer_with_hash ( decrypted ) := SHA1(answer) + answer + (0-15 random bytes) //
		Logging::log('Aes','Decrypt ige length = '.strlen($decrypted),0);
		$dhInner = new Binary();
		$dhInner->write($decrypted);
		$dhInner->read(20); // the first 20 bytes of answer_with_hash must be equal to SHA1 //
		// server_DH_inner_data#b5890dba nonce:int128 server_nonce:int128 g:int dh_prime:string g_a:string server_time:int = Server_DH_inner_data; //
		$serverDHInnerData = $dhInner->tgreadObject();
		assert($serverDHInnerData->nonce === $nonce,'Nonce from server is not equal to nonce !');
		assert($serverDHInnerData->server_nonce === $serverNonce,'Server nonce from server is not equal to server nonce !');
		$g = $serverDHInnerData->g;
		$dhPrime = strval(gmp_import($serverDHInnerData->dh_prime));
		$g_a = gmp_import($serverDHInnerData->g_a);
		$serverTime = $serverDHInnerData->server_time;
		$timeOffset = $serverTime - time();
		for($retryId = 0;$retryId < 3;$retryId++):
			$b = strval(gmp_import(random_bytes(0x800)));
			$g_b = strval(gmp_powm($g,$b,$dhPrime));
			$g_a_b = strval(gmp_powm($g_a,$b,$dhPrime));
			Helper::checkG(strval($g),$dhPrime);
			Helper::checkG(strval($g_a),$dhPrime,true);
			Helper::checkG(strval($g_b),$dhPrime,true);
			$clientDHData = (new \Tak\Liveproto\Tl\Types\Other\ClientDHInnerData)(nonce : $nonce,server_nonce : $serverNonce,retry_id : $retryId,g_b : Helper::getByteArray($g_b));
			$data = $clientDHData->read();
			$clientDhEncrypted = Aes::encrypt(sha1($data,true).$data,$key,$iv);
			Logging::log('Aes','Encrypt ige length = '.strlen($clientDhEncrypted),0);
			$setClientDH = $sender(new \Tak\Liveproto\Tl\Functions\Other\SetClientDHParams,nonce : $nonce,server_nonce : $serverNonce,encrypted_data : $clientDhEncrypted);
			assert($setClientDH->nonce === $nonce,'Nonce from server is not equal to nonce !');
			assert($setClientDH->server_nonce === $serverNonce,'Server nonce from server is not equal to server nonce !');
			$authKey = new AuthKey(gab : $g_a_b,expires_at : $expires_at);
			if($setClientDH instanceof \Tak\Liveproto\Tl\Types\Other\DhGenOk):
				$newNonceHashCalculated = $authKey->calcNewNonceHash(gmp_export($newNonce,0x20),0x1);
				assert($setClientDH->new_nonce_hash1 === $newNonceHashCalculated,'Nonce hash 1 is not equal to nonce hash calculated !');
				$genSalt = new Binary();
				$genSalt->write(substr(gmp_export($newNonce,0x20),0,8) ^ substr(gmp_export($serverNonce,0x10),0,8));
				$salt = $genSalt->readLong();
				Logging::log('Generate','Salt : '.$salt,0);
				Logging::log('Connect','OK !',0);
				return array($authKey,$timeOffset,$salt);
			elseif($setClientDH instanceof \Tak\Liveproto\Tl\Types\Other\DhGenRetry):
				$newNonceHashCalculated = $authKey->calcNewNonceHash(gmp_export($newNonce,0x20),0x2);
				assert($setClientDH->new_nonce_hash2 === $newNonceHashCalculated,'Nonce hash 2 is not equal to nonce hash calculated !');
				Logging::log('Connect','Dh gen retry !',E_ERROR);
				continue;
			elseif($setClientDH instanceof \Tak\Liveproto\Tl\Types\Other\DhGenFail):
				$newNonceHashCalculated = $authKey->calcNewNonceHash(gmp_export($newNonce,0x20),0x3);
				assert($setClientDH->new_nonce_hash3 === $newNonceHashCalculated,'Nonce hash 3 is not equal to nonce hash calculated !');
				Logging::log('Connect','Dh gen fail !',E_ERROR);
				break;
			else:
				Logging::log('Connect','Fail !'.$setClientDH,0);
			endif;
		endfor;
		throw new \RuntimeException('Authentication failed !');
	}
}

?>