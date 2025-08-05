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

use Amp\DeferredFuture;

use Amp\TimeoutCancellation;

use Revolt\EventLoop;

final class Sender {
	private readonly object $load;
	private array $msgIds = array();
	private array $pendingAcks = array();
	private int $lastAckTime = 0;
	private array $received = array();
	private array $receiveQueue = array();
	private string $receiveLoop;

	public const UPDATES = array(0xe317af7e,0x313bc7f8,0x4d6deea5,0x78d4dec1,0x725b04c3,0x74ae4240,0x9015e101);

	public function __construct(protected object $transport,private readonly object $session,private object $handler){
		$this->load = $session->load();
		$this->receiveLoop = strval(null);
		$this->receiveLoop = EventLoop::defer($this->receivePacket(...));
		EventLoop::setErrorHandler($this->errors(...));
	}
	public function objectHash(object $class) : string {
		return md5(spl_object_hash($class));
	}
	public function send(Binary $request) : void {
		EventLoop::queue($this->sendPacket(...),request : $request);
	}
	public function sendAcknowledgement() : void {
		$acks = array_unique($this->pendingAcks);
		$elapsed = intval(time() - $this->lastAckTime);
		# https://core.telegram.org/mtproto/service_messages_about_messages#acknowledgment-of-receipt #
		if($acks and (count($acks) >= 0x10 or (60 <= $elapsed and $elapsed <= 120))):
			$msgAck = new \Tak\Liveproto\Tl\Types\Other\MsgsAck(['msg_ids'=>$acks]);
			EventLoop::queue($this->sendPacket(...),request : $msgAck->stream());
			$this->pendingAcks = array();
			$this->lastAckTime = time();
		endif;
	}
	public function sendPacket(Binary $request,? int $messageId = null) : void {
		$message_id = is_null($messageId) ? $this->session->getNewMsgId() : $messageId;
		$data = $this->composePlainMessage(request : $request,salt : $this->load->salt,session_id : $this->load->id,message_id : $message_id,sequence : $this->session->generateSequence());
		$message = $this->encryptMTProtoMessage(data : $data,version : 2);
		Logging::log('Send Packet','Request : '.strval($request).' , Packet length : '.strlen($message).' , Message ID : '.$message_id,0);
		$this->transport->send($message);
	}
	public function composePlainMessage(Binary $request,int $salt,int $session_id,int $message_id,int $sequence) : string {
		$plainWriter = new Binary();
		$plainWriter->writeLong($salt);
		$plainWriter->writeLong($session_id);
		$this->msgIds[$this->objectHash($request)] = $message_id;
		$plainWriter->writeLong($message_id);
		$plainWriter->writeInt($sequence);
		$packet = $request->read();
		$plainWriter->writeInt(strlen($packet));
		$plainWriter->write($packet);
		return $plainWriter->read();
	}
	public function encryptMTProtoMessage(string $data,int $version) : string {
		if($version === 1):
			# $padding = random_bytes(strlen($data) % 16);
			$padding = random_bytes(8);
			$msgKeyLarge = sha1($data,true);
			$msgKey = substr($msgKeyLarge,4,16);
			list($key,$iv) = Helper::aesCalculate($this->load->auth_key->key,$msgKey,true);
		elseif($version === 2):
			$fmod = fn(int $a,int $b) : int => ($a % $b + $b) % $b;
			$padding = random_bytes($fmod(-(strlen($data) + 12),16) + 12);
			$msgKeyLarge = hash('sha256',substr($this->load->auth_key->key,88,32).$data.$padding,true);
			$msgKey = substr($msgKeyLarge,8,16);
			list($key,$iv) = Helper::keyCalculate($this->load->auth_key->key,$msgKey,true);
		else:
			throw new \InvalidArgumentException('The MTProto version argument is invalid !');
		endif;
		$encrypt = Aes::encrypt($data.$padding,$key,$iv);
		$cipherWriter = new Binary();
		$cipherWriter->writeLong($this->load->auth_key->id);
		$cipherWriter->write($msgKey);
		$cipherWriter->write($encrypt);
		return $cipherWriter->read();
	}
	# https://core.telegram.org/api/pfs#related-articles #
	public function bindTempAuthKey(self $sender,int $temp_auth_key_id,int $expires_at) : bool {
		$nonce = Helper::generateRandomLong();
		$authKeyInner = new \Tak\Liveproto\Tl\Types\Other\BindAuthKeyInner(['nonce'=>$nonce,'temp_auth_key_id'=>$temp_auth_key_id,'perm_auth_key_id'=>$this->load->auth_key->id,'temp_session_id'=>$this->load->id,'expires_at'=>$expires_at]);
		$bindInner = $authKeyInner->stream();
		$try = 3;
		try {
			bindtemp:
			$try--;
			$message_id = $this->session->getNewMsgId();
			$data = $this->composePlainMessage(request : $bindInner,salt : Helper::generateRandomLong(),session_id : Helper::generateRandomLong(),message_id : $message_id,sequence : 0);
			$cipher = $this->encryptMTProtoMessage(data : $data,version : 1);
			$bindTemp = new \Tak\Liveproto\Tl\Functions\Auth\BindTempAuthKey(['perm_auth_key_id'=>$this->load->auth_key->id,'nonce'=>$nonce,'expires_at'=>$expires_at,'encrypted_message'=>$cipher]);
			$binary = $bindTemp->stream();
			Logging::log('Bind Temp','Expires at : '.strval($expires_at).' , EncryptedMessage ID : '.$message_id,0);
			$sender->sendPacket(request : $binary,messageId : $message_id);
			return $sender->receive(request : $binary,timeout : 10)->bool;
		} catch(Errors $error){
			if($error->getCode() == 400):
				if($try > 0):
					goto bindtemp;
				endif;
			endif;
			throw $error;
		}
	}
	public function receive(Binary $request,float $timeout) : object {
		$deferred = new DeferredFuture();
		$future = $deferred->getFuture();
		$this->receiveQueue[$this->objectHash($request)] = (object) ['request'=>$request,'deferred'=>$deferred];
		$cancellation = $timeout > 0 ? new TimeoutCancellation($timeout) : null;
		return $future->await($cancellation);
	}
	public function receivedLoop() : void {
		foreach($this->received as $hash => $object):
			if(array_key_exists($hash,$this->receiveQueue)):
				extract((array) $this->receiveQueue[$hash]);
				if($deferred->isComplete()):
					unset($this->receiveQueue[$hash]);
					gc_collect_cycles();
				else:
					switch($object->status):
						case 'success':
							if(isset($object->result->vector) and is_callable($object->result->vector)):
								$constructor = All::getConstructor($request->readInt());
								$comments = Tl::parseDocComment($constructor);
								$return = Tl::parseType($comments['return']);
								$object->result->vector = call_user_func($object->result->vector,$return['type'],true);
								$request->undo();
							endif;
							if(isset($object->result->bool) and is_callable($object->result->bool)):
								$object->result->bool = call_user_func($object->result->bool,true);
							endif;
							if(isset($object->result->chats,$object->result->users) and is_array($object->result->chats) and is_array($object->result->users)):
								$this->handler->saveAccessHash($object->result);
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
	}
	public function receivePacket() : void {
		while(isset($this->receiveLoop)):
			try {
				$body = $this->transport->receive();
				$closure = function(string $result) : void {
					list($message,$remoteMessageId,$remoteSequence) = $this->decodeMessage($result);
					$reader = new Binary();
					$reader->write($message);
					$this->processMessage($remoteMessageId,$remoteSequence,$reader);
					$this->receivedLoop();
				};
				EventLoop::queue($closure,$body);
			} catch(\Throwable $error){
				Logging::log('Receive Packet',$error->getMessage(),E_WARNING);
				if(isset($this->receiveLoop)) $this->ping();
			}
		endwhile;
	}
	public function decodeMessage(string $body) : array {
		$reader = new Binary();
		$reader->write($body);
		if(strlen($body) < 8):
			Logging::log('Decode Message','Body length is less than 8 !',E_ERROR);
		endif;
		$remoteAuthKeyId = $reader->readLong();
		if($remoteAuthKeyId !== $this->load->auth_key->id):
			Logging::log('Decode Message','Server replied with an invalid auth key !',E_ERROR);
		endif;
		$msgKey = $reader->read(16);
		list($key,$iv) = Helper::keyCalculate($this->load->auth_key->key,$msgKey,false);
		$cipher = $reader->read();
		$plain = Aes::decrypt($cipher,$key,$iv);
		$ourKey = hash('sha256',substr($this->load->auth_key->key,96,32).$plain,true);
		if($msgKey !== substr($ourKey,8,16)):
			Logging::log('Decode Message','Received msg key does not match with expected one !',E_ERROR);
		endif;
		$plainReader = new Binary();
		$plainReader->write($plain);
		$remoteSalt = $plainReader->readLong();
		$remoteSessionId = $plainReader->readLong();
		if($remoteSessionId !== $this->load->id):
			Logging::log('Decode Message','Server replied with a wrong session ID !',E_ERROR);
		endif;
		$remoteMessageId = $plainReader->readLong();
		if($remoteMessageId % 2 !== 1):
			Logging::log('Decode Message','Server sent an even msg id !',E_ERROR);
		endif;
		$remoteSequence = $plainReader->readInt();
		$messageLength = $plainReader->readInt();
		$message = $plainReader->read($messageLength);
		return array($message,$remoteMessageId,$remoteSequence);
	}
	public function processMessage(int $messageId,int $sequence,Binary $reader) : void {
		$object = strval($reader);
		$constructorId = $reader->readInt();
		Logging::log('Process Message',(class_exists($object) ? 'Object : '.$object : 'Constructor Number : 0x'.dechex($constructorId)).', Message ID : '.$messageId,0);
		# pong#347773c5 msg_id:long ping_id:long = Pong; #
		if($constructorId == 0x347773c5):
			Logging::log('Live','Pong !',0);
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
			$this->load['salt'] = $server_salt;
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
		elseif(in_array($constructorId,self::UPDATES)):
			$this->handler->processUpdate($reader->tgreadObject(true));
		else:
			Logging::log('Process Message','Unknown message : 0x'.dechex($constructorId),E_WARNING);
			var_dump($reader->tgreadObject(true));
		endif;
		$this->pendingAcks []= $messageId;
		$this->sendAcknowledgement();
	}
	public function ping() : void {
		$ping = new \Tak\Liveproto\Tl\Functions\Other\Ping(['ping_id'=>random_int(PHP_INT_MIN,PHP_INT_MAX)]);
		/*
		You can also behave like this...
		$ping = new \Tak\Liveproto\Tl\Functions\Other\PingDelayDisconnect(['ping_id'=>random_int(PHP_INT_MIN,PHP_INT_MAX),'disconnect_delay'=>120]);
		*/
		$this->send($ping->stream());
		Logging::log('Live','Ping ...',0);
	}
	public function errors(\Throwable $error) : never {
		Logging::log('Sender',$error->getMessage(),E_ERROR);
		throw $error;
	}
	public function close() : void {
		unset($this->receiveLoop);
		Logging::log('Sender','Closed !',E_WARNING);
	}
}

?>