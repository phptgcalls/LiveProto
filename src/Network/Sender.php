<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network;

use Tak\Liveproto\Crypto\Aes;

use Tak\Liveproto\Utils\Binary;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Errors;

use Tak\Liveproto\Utils\Logging;

use Tak\Liveproto\Tl\All;

use Tak\Liveproto\Parser\Tl;

use Tak\Liveproto\Enums\NonRpcResult;

use Amp\DeferredFuture;

use Amp\TimeoutCancellation;

use Amp\Sync\LocalMutex;

use Revolt\EventLoop;

final class Sender {
	private readonly object $load;
	private array $msgIds = array();
	private array $identifiers = array();
	private array $pendingAcks = array();
	private int $lastAckTime = 0;
	private array $received = array();
	private array $receiveQueue = array();
	private string $receiveLoop;

	public const UPDATES = array(0xe317af7e,0x313bc7f8,0x4d6deea5,0x78d4dec1,0x725b04c3,0x74ae4240,0x9015e101);

	public function __construct(protected object $transport,private readonly object $session,private object $handler){
		$this->load = $session->load();
		$session_id = $this->load->id;
		$session->reset();
		$this->receiveLoop = strval(null);
		$this->receiveLoop = EventLoop::defer($this->receivePacket(...));
		$this->destroy($session_id);
		EventLoop::setErrorHandler($this->errors(...));
	}
	public function objectHash(object $class) : string {
		return md5(spl_object_hash($class));
	}
	public function send(Binary $request) : void {
		EventLoop::queue($this->sendPacket(...),request : $request);
	}
	# https://core.telegram.org/mtproto/service_messages_about_messages#acknowledgment-of-receipt #
	public function sendAcknowledgement() : void {
		$acks = array_unique($this->pendingAcks);
		$elapsed = intval(time() - $this->lastAckTime);
		if($acks and (count($acks) >= 0x10 or (60 <= $elapsed and $elapsed <= 120))):
			$msgAck = new \Tak\Liveproto\Tl\Types\Other\MsgsAck(['msg_ids'=>$acks]);
			EventLoop::queue($this->sendPacket(...),request : $msgAck->stream());
			$this->pendingAcks = array();
			$this->lastAckTime = time();
		endif;
	}
	public function sendPacket(Binary $request,? int $messageId = null,mixed $identifier = null) : void {
		$message_id = is_null($messageId) ? $this->session->getNewMsgId() : $messageId;
		$data = $this->composePlainMessage(request : $request,salt : $this->load->salt,session_id : $this->load->id,message_id : $message_id,sequence : $this->session->generateSequence());
		$message = $this->encryptMTProtoMessage(data : $data,version : 2);
		$this->msgIds[$this->objectHash($request)] = $message_id;
		if(is_null($identifier) === false):
			$this->identifiers[$this->objectHash($request)] = $identifier;
		endif;
		Logging::log('Send Packet','Request : '.strval($request).' , Packet length : '.strlen($message).' , Message ID : '.$message_id,0);
		$this->transport->send($message);
	}
	public function composePlainMessage(Binary $request,int $salt,int $session_id,int $message_id,int $sequence) : string {
		$plainWriter = new Binary();
		$plainWriter->writeLong($salt);
		$plainWriter->writeLong($session_id);
		$plainWriter->writeLong($message_id);
		$plainWriter->writeInt($sequence);
		$packet = $request->read();
		$plainWriter->writeInt(strlen($packet));
		$plainWriter->write($packet);
		return $plainWriter->read();
	}
	public function encryptMTProtoMessage(string $data,int $version) : string {
		if($version === 1):
			$msgKeyLarge = sha1($data,true);
			$msgKey = substr($msgKeyLarge,4,16);
			list($key,$iv) = Helper::aesCalculate($this->load->auth_key->key,$msgKey,true);
		elseif($version === 2):
			$fmod = fn(int $a,int $b) : int => ($a % $b + $b) % $b;
			$data .= random_bytes($fmod(-(strlen($data) + 12),16) + 12);
			$msgKeyLarge = hash('sha256',substr($this->load->auth_key->key,88,32).$data,true);
			$msgKey = substr($msgKeyLarge,8,16);
			list($key,$iv) = Helper::keyCalculate($this->load->auth_key->key,$msgKey,true);
		else:
			throw new \InvalidArgumentException('The MTProto version argument is invalid !');
		endif;
		$encrypt = Aes::encrypt($data,$key,$iv);
		$cipherWriter = new Binary();
		$cipherWriter->writeLong($this->load->auth_key->id);
		$cipherWriter->write($msgKey);
		$cipherWriter->write($encrypt);
		return $cipherWriter->read();
	}
	# https://core.telegram.org/api/pfs#related-articles #
	public function bindTempAuthKey(self $sender,int $temp_auth_key_id,int $temp_session_id,int $expires_at) : bool {
		$nonce = Helper::generateRandomLong();
		$authKeyInner = new \Tak\Liveproto\Tl\Types\Other\BindAuthKeyInner(['nonce'=>$nonce,'temp_auth_key_id'=>$temp_auth_key_id,'perm_auth_key_id'=>$this->load->auth_key->id,'temp_session_id'=>$temp_session_id,'expires_at'=>$expires_at]);
		$bindInner = $authKeyInner->stream();
		$try = 3;
		do {
			try {
				$message_id = $this->session->getNewMsgId();
				$data = $this->composePlainMessage(request : $bindInner,salt : Helper::generateRandomLong(),session_id : Helper::generateRandomLong(),message_id : $message_id,sequence : 0);
				$cipher = $this->encryptMTProtoMessage(data : $data,version : 1);
				$bindTemp = new \Tak\Liveproto\Tl\Functions\Auth\BindTempAuthKey(['perm_auth_key_id'=>$this->load->auth_key->id,'nonce'=>$nonce,'expires_at'=>$expires_at,'encrypted_message'=>$cipher]);
				$binary = $bindTemp->stream();
				Logging::log('Bind Temp','Expires at : '.strval($expires_at).' , EncryptedMessage ID : '.$message_id,0);
				$sender->sendPacket(request : $binary,messageId : $message_id);
				return $sender->receive(request : $binary,timeout : 10);
			} catch(Errors $error){
				$code = $error->getCode();
			} finally {
				$try--;
			}
		} while($try > 0 and $code == 400);
		throw new RuntimeException('Failed to create a temporary client !');
	}
	public function receive(Binary $request,float $timeout) : mixed {
		$deferred = new DeferredFuture();
		$future = $deferred->getFuture();
		$this->receiveQueue[$this->objectHash($request)] = ['request'=>$request,'deferred'=>$deferred];
		$cancellation = $timeout > 0 ? new TimeoutCancellation($timeout) : null;
		return $future->await($cancellation);
	}
	public function receivedLoop() : void {
		static $mutex = new LocalMutex;
		$lock = $mutex->acquire();
		foreach($this->received as $hash => $object):
			if(array_key_exists($hash,$this->receiveQueue)):
				extract($this->receiveQueue[$hash]);
				if($deferred->isComplete()):
					unset($this->receiveQueue[$hash]);
					gc_collect_cycles();
				else:
					switch($object->status):
						case 'success':
							if(isset($object->result->chats,$object->result->users) and is_array($object->result->chats) and is_array($object->result->users)):
								$this->handler->saveAccessHash($object->result);
							endif;
							if(isset($object->result->vector) and is_callable($object->result->vector)):
								$constructor = All::getConstructor($request->readInt());
								$comments = Tl::parseDocComment($constructor);
								$return = Tl::parseType($comments['return']);
								$object->result = call_user_func($object->result->vector,$return['type'],true);
								$request->undo();
							endif;
							if(isset($object->result->bool) and is_callable($object->result->bool)):
								$object->result = call_user_func($object->result->bool,true);
							endif;
							$deferred->complete($object->result);
							break;
						case 'error':
							$deferred->error($object->exception);
							break;
						case 'resend':
							$this->send($request);
							break;
					endswitch;
					unset($this->received[$hash]);
				endif;
			endif;
		endforeach;
		$lock->release();
	}
	public function receivePacket() : void {
		while(isset($this->receiveLoop)):
			try {
				$body = $this->transport->receive();
				$closure = function(string $result) : void {
					$plain = $this->decryptMTProtoMessage(data : $result,version : 2);
					list($message,$remoteMessageId,$remoteSequence) = $this->decomposePlainMessage($plain);
					$reader = new Binary();
					$reader->write($message);
					$this->processMessage($remoteMessageId,$remoteSequence,$reader);
					$this->receivedLoop();
				};
				EventLoop::queue($closure,$body);
			} catch(\Throwable $error){
				Logging::log('Receive Packet',$error->getMessage(),E_WARNING);
				$this->ping();
			}
		endwhile;
	}
	public function decryptMTProtoMessage(string $data,int $version) : string {
		$reader = new Binary();
		$reader->write($data);
		if(strlen($data) < 8):
			Logging::log('Decode Message','Body length is less than 8 !',E_NOTICE);
		endif;
		$remoteAuthKeyId = $reader->readLong();
		if($remoteAuthKeyId !== $this->load->auth_key->id):
			Logging::log('Decode Message','Server replied with an invalid auth key !',E_ERROR);
		endif;
		$msgKey = $reader->read(16);
		if($version === 1):
			list($key,$iv) = Helper::aesCalculate($this->load->auth_key->key,$msgKey,false);
		else:
			list($key,$iv) = Helper::keyCalculate($this->load->auth_key->key,$msgKey,false);
		endif;
		$cipher = $reader->read();
		$plain = Aes::decrypt($cipher,$key,$iv);
		if($version === 1):
			$ourKey = sha1($plain,true);
			$ourKey = substr($ourKey,4,16);
		else:
			$ourKey = hash('sha256',substr($this->load->auth_key->key,96,32).$plain,true);
			$ourKey = substr($ourKey,8,16);
		endif;
		if($msgKey !== $ourKey):
			Logging::log('Decode Message','Received msg key does not match with expected one !',E_ERROR);
		endif;
		return strval($plain);
	}
	public function decomposePlainMessage(string $plain) : array {
		$plainReader = new Binary();
		$plainReader->write($plain);
		$remoteSalt = $plainReader->readLong();
		$remoteSessionId = $plainReader->readLong();
		if($remoteSessionId !== $this->load->id):
			Logging::log('Decode Message','Server replied with a wrong session id !',E_ERROR);
		endif;
		$remoteMessageId = $plainReader->readLong();
		if($remoteMessageId % 2 !== 1):
			Logging::log('Decode Message','Server sent an even message id !',E_ERROR);
		endif;
		$remoteSequence = $plainReader->readInt();
		$messageLength = $plainReader->readInt();
		$message = $plainReader->read($messageLength);
		$padding = strlen($plainReader->read());
		if($padding < 12 or $padding > 1024):
			Logging::log('Decode Message','Padding must be between 12 and 1024 bytes !',E_ERROR);
		endif;
		return array($message,$remoteMessageId,$remoteSequence);
	}
	public function processMessage(int $messageId,int $sequence,Binary $reader) : void {
		$object = strval($reader);
		$constructorId = $reader->readInt();
		Logging::log('Process Message',(class_exists($object) ? 'Object : '.$object : 'Constructor Number : 0x'.dechex($constructorId)).' , Message ID : '.$messageId,0);
		# pong#347773c5 msg_id:long ping_id:long = Pong; #
		if($constructorId == 0x347773c5):
			$msg_id = $reader->readLong();
			$ping_id = $reader->readLong();
			if(in_array($msg_id,$this->msgIds)):
				$hash = array_search($msg_id,$this->msgIds);
				$this->received[$hash] = (object) ['status'=>'success','result'=>$ping_id];
				Logging::log('Live','Pong !',0);
			endif;
			return;
		# msg_container is used instead of msg_copy #
		# msg_container#73f1f8dc messages:vector<message> = MessageContainer; #
		elseif($constructorId == 0x73f1f8dc):
			$messages = $reader->readInt();
			# message msg_id:long seqno:int bytes:int body:Object = Message; #
			for($i = 0;$i < $messages;$i++):
				$msg_id = $reader->readLong();
				$seq_no = $reader->readInt();
				$length = $reader->readInt();
				$position = $reader->tellPosition();
				$this->processMessage($msg_id,$seq_no,$reader);
				$reader->setPosition($length + $position);
			endfor;
			return;
		# gzip_packed#3072cfa1 packed_data:string = Object; #
		elseif($constructorId == 0x3072cfa1):
			$packed_data = $reader->tgreadBytes();
			$unpacked = gzdecode($packed_data);
			$reader = new Binary();
			$reader->write($unpacked);
			$this->processMessage($messageId,$sequence,$reader);
			return;
		# msgs_ack#62d6b459 msg_ids:Vector<long> = MsgsAck; #
		elseif($constructorId == 0x62d6b459):
			$msg_ids = $reader->tgreadVector('long');
			Logging::log('Msgs Ack',implode(chr(0x20).chr(0x2c).chr(0x20),$msg_ids),0);
			return;
		# rpc_result#f35c6d01 req_msg_id:long result:Object = RpcResult; #
		elseif($constructorId == 0xf35c6d01):
			$req_msg_id = $reader->readLong();
			if(in_array($req_msg_id,$this->msgIds)):
				$constructorId = $reader->readInt();
				# gzip_packed#3072cfa1 packed_data:string = Object; #
				if($constructorId === 0x3072cfa1):
					$packed_data = $reader->tgreadBytes();
					$unpacked = gzdecode($packed_data);
					$reader = new Binary();
					$reader->write($unpacked);
					$constructorId = $reader->readInt();
				endif;
				# rpc_error#2144ca19 error_code:int error_message:string = RpcError; #
				if($constructorId === 0x2144ca19):
					$error_code = $reader->readInt();
					$error_message = $reader->tgreadBytes();
					Logging::log('RPC',$error_code.chr(32).$error_message,E_ERROR);
					$hash = array_search($req_msg_id,$this->msgIds);
					$this->received[$hash] = (object) ['status'=>'error','exception'=>new Errors($error_message,$error_code)];
				# rpc_answer_unknown#5e2ad36e = RpcDropAnswer; #
				elseif($constructorId === 0x5e2ad36e):
					# nothing ! #
				# rpc_answer_dropped_running#cd78e586 = RpcDropAnswer; #
				elseif($constructorId === 0xcd78e586):
					# again nothing ! #
				# rpc_answer_dropped#a43ad8b7 msg_id:long seq_no:int bytes:int = RpcDropAnswer; #
				elseif($constructorId === 0xa43ad8b7):
					$msg_id = $reader->readLong();
					$seq_no = $reader->readInt();
					$bytes = $reader->readInt();
				else:
					$result = $reader->tgreadObject(true);
					$hash = array_search($req_msg_id,$this->msgIds);
					$this->received[$hash] = (object) ['status'=>'success','result'=>$result];
					if(in_array($constructorId,self::UPDATES)):
						$this->handler->processUpdate($result);
					endif;
				endif;
			endif;
		# new_session_created#9ec20908 first_msg_id:long unique_id:long server_salt:long = NewSession #
		elseif($constructorId == 0x9ec20908):
			$first_msg_id = $reader->readLong();
			$unique_id = $reader->readLong();
			$server_salt = $reader->readLong();
			# $session_id = $this->load->id;
			# $this->session->reset();
			# $this->load['id'] = $unique_id;
			$this->load['salt'] = $server_salt;
			# $this->destroy($session_id);
			Logging::log('New Session Created','First Message ID : '.$first_msg_id,0);
		# bad_msg_notification#a7eff811 bad_msg_id:long bad_msg_seqno:int error_code:int = BadMsgNotification; #
		elseif($constructorId == 0xa7eff811):
			$bad_msg_id = $reader->readLong();
			if(in_array($bad_msg_id,$this->msgIds)):
				$status_msg = (object) ['status'=>'resend'];
				$bad_msg_seqno = $reader->readInt();
				$error_code = $reader->readInt();
				if(in_array($error_code,array(16,17))):
					$this->session->updateTimeOffset($bad_msg_id);
				elseif($error_code == 18):
					$this->load['sequence'] = ceil($this->load['sequence'] / 4) * 4;
				elseif($error_code == 32):
					$this->load['sequence'] += 64;
				elseif($error_code == 33):
					$this->load['sequence'] -= 16;
				elseif(in_array($error_code,array(34,35))):
					$this->load['sequence'] += 1;
				else:
					$status_msg = (object) ['status'=>'error','exception'=>new Errors('Bad Msg Notification !',$error_code)];
				endif;
				$hash = array_search($bad_msg_id,$this->msgIds);
				$this->received[$hash] = $status_msg;
				Logging::log('Bad Msg Notification','Bad Message ID : '.$bad_msg_id.' , Error Code : '.$error_code,0);
			endif;
		# bad_server_salt#edab447b bad_msg_id:long bad_msg_seqno:int error_code:int new_server_salt:long = BadMsgNotification; #
		elseif($constructorId == 0xedab447b):
			$bad_msg_id = $reader->readLong();
			if(in_array($bad_msg_id,$this->msgIds)):
				$bad_msg_seqno = $reader->readInt();
				$error_code = $reader->readInt(); // 48: incorrect server salt (in this case, the bad_server_salt response is received with the correct salt, and the message is to be re-sent with it) //
				$new_server_salt = $reader->readLong();
				$this->load['salt'] = $new_server_salt;
				$hash = array_search($bad_msg_id,$this->msgIds);
				$this->received[$hash] = (object) ['status'=>'resend'];
				Logging::log('Bad Server Salt','Bad Message ID : '.$bad_msg_id,0);
			endif;
		# msg_detailed_info#276d3ec6 msg_id:long answer_msg_id:long bytes:int status:int = MsgDetailedInfo; #
		elseif($constructorId == 0x276d3ec6):
			$msg_id = $reader->readLong();
			$answer_msg_id = $reader->readLong();
			$bytes = $reader->readInt();
			$status = $reader->readInt();
			$this->pendingAcks []= $answer_msg_id;
		# msg_new_detailed_info#809db6df answer_msg_id:long bytes:int status:int = MsgDetailedInfo; #
		elseif($constructorId == 0x809db6df):
			$answer_msg_id = $reader->readLong();
			$bytes = $reader->readInt();
			$status = $reader->readInt();
			$this->pendingAcks []= $answer_msg_id;
		# future_salts#ae500895 req_msg_id:long now:int salts:vector<future_salt> = FutureSalts; #
		elseif($constructorId == 0x809db6df):
			$req_msg_id = $reader->readLong();
			if(in_array($req_msg_id,$this->msgIds)):
				$now = $reader->readInt();
				$salts = $reader->tgreadVector('future_salt');
				$this->load['salt'] = end($salts);
				$hash = array_search($req_msg_id,$this->msgIds);
				$this->received[$hash] = (object) ['status'=>'success','result'=>$salts];
				Logging::log('Future Salts','Number of salts : '.count($salts),0);
			endif;
		# destroy_session_ok#e22045fc session_id:long = DestroySessionRes; #
		# destroy_session_none#62d350c9 session_id:long = DestroySessionRes; #
		elseif(in_array($constructorId,array(0xe22045fc,0x62d350c9))):
			$result = $reader->tgreadObject(true);
			$hash = array_search(NonRpcResult::DESTROY_SESSION,$this->identifiers);
			$this->received[$hash] = (object) ['status'=>'success','result'=>$result];
			Logging::log('Destroy Session Res','Session Id : '.$result->session_id,0);
		# destroy_auth_key_ok#f660e1d4 = DestroyAuthKeyRes; #
		# destroy_auth_key_none#0a9f2259 = DestroyAuthKeyRes; #
		# destroy_auth_key_fail#ea109b13 = DestroyAuthKeyRes; #
		elseif(in_array($constructorId,array(0xf660e1d4,0x0a9f2259,0xea109b13))):
			$result = $reader->tgreadObject(true);
			$hash = array_search(NonRpcResult::DESTROY_AUTH_KEY,$this->identifiers);
			$this->received[$hash] = (object) ['status'=>'success','result'=>$result];
			Logging::log('Destroy Auth',$result->getClass(),E_WARNING);
		elseif(in_array($constructorId,self::UPDATES)):
			$this->handler->processUpdate($reader->tgreadObject(true));
		else:
			Logging::log('Process Message','Unknown message : 0x'.dechex($constructorId),E_WARNING);
			var_dump($reader->tgreadObject(true));
		endif;
		$this->pendingAcks []= $messageId;
		$this->sendAcknowledgement();
	}
	public function destroy(int $session_id) : void {
		if(isset($this->receiveLoop) and $session_id !== 0):
			$result = $this(raw : new \Tak\Liveproto\Tl\Functions\Other\DestroySession(['session_id'=>$session_id]),identifier : NonRpcResult::DESTROY_SESSION);
			if($result instanceof \Tak\Liveproto\Tl\Types\Other\DestroySessionOk):
				$this->session->reset(id : $session_id);
			endif;
			Logging::log('Destroy Session','Session Id : '.$session_id.' , Result : '.$result->getClass(),0);
		endif;
	}
	public function ping() : void {
		if(isset($this->receiveLoop)):
			$ping_id = random_int(PHP_INT_MIN,PHP_INT_MAX);
			Logging::log('Live','Ping ...',0);
			# $pong_id = $this(raw : new \Tak\Liveproto\Tl\Functions\Other\PingDelayDisconnect(['ping_id'=>$ping_id,'disconnect_delay'=>75]));
			$raw = new \Tak\Liveproto\Tl\Functions\Other\PingDelayDisconnect(['ping_id'=>$ping_id,'disconnect_delay'=>75]);
			$binary = $raw->stream();
			$this->sendPacket(request : $binary);
		endif;
	}
	public function errors(\Throwable $error) : never {
		Logging::log('Sender',$error->getMessage(),E_ERROR);
		throw $error;
	}
	public function close() : void {
		unset($this->receiveLoop);
		Logging::log('Sender','Closed !',E_WARNING);
	}
	public function __invoke(object $raw,float $timeout = 10,mixed $identifier = null) : object {
		$binary = $raw->stream();
		$this->sendPacket(request : $binary,identifier : $identifier);
		return $this->receive(request : $binary,timeout : $timeout);
	}
	public function __destruct(){
		$this->close();
	}
}

?>