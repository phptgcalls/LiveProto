<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Handlers;

use Tak\Liveproto\Utils\Instance;

use Tak\Liveproto\Utils\Logging;

use Tak\Liveproto\Filters\Filter;

use Amp\DeferredFuture;

use Amp\DeferredCancellation;

use Amp\TimeoutException;

use Amp\Sync\LocalMutex;

use Amp\Sync\Lock;

use Revolt\EventLoop;

use Closure;

use Throwable;

use function Amp\async;

/*
updates.state#a56c2a3e pts:int qts:int date:int seq:int unread_count:int = updates.State;

updates.differenceEmpty#5d75a138 date:int seq:int = updates.Difference;
updates.difference#f49ca0 new_messages:Vector<Message> new_encrypted_messages:Vector<EncryptedMessage> other_updates:Vector<Update> chats:Vector<Chat> users:Vector<User> state:updates.State = updates.Difference;
updates.differenceSlice#a8fb1981 new_messages:Vector<Message> new_encrypted_messages:Vector<EncryptedMessage> other_updates:Vector<Update> chats:Vector<Chat> users:Vector<User> intermediate_state:updates.State = updates.Difference;
updates.differenceTooLong#4afe8f6d pts:int = updates.Difference;

updates.channelDifferenceEmpty#3e11affb flags:# final:flags.0?true pts:int timeout:flags.1?int = updates.ChannelDifference;
updates.channelDifferenceTooLong#a4bcc6fe flags:# final:flags.0?true timeout:flags.1?int dialog:Dialog messages:Vector<Message> chats:Vector<Chat> users:Vector<User> = updates.ChannelDifference;
updates.channelDifference#2064674e flags:# final:flags.0?true pts:int timeout:flags.1?int new_messages:Vector<Message> other_updates:Vector<Update> chats:Vector<Chat> users:Vector<User> = updates.ChannelDifference;
*/

final class Updates {
	public readonly object $load;
	private array $handlers;
	protected LocalMutex $mutex;
	protected Lock $lock;

	public function __construct(public readonly object $client,private readonly object $session){
		$this->load = $session->load();
		$this->handlers = array();
		$this->mutex = new LocalMutex;
	}
	public function addEventHandler(object | callable $callback,? string $unique = null,object | array ...$filters) : void {
		if(is_object($callback) and is_a($callback,'Closure') === false):
			$functions = Filter::getFunctions($callback,$unique);
			$this->handlers = array_merge($this->handlers,$functions);
		else:
			$function = Filter::getFunction($callback,$unique);
			$additional = array_map(fn(object | array $filter) : array => array_values(array_filter(is_array($filter) ? $filter : array($filter),fn(mixed $index) : bool => $index instanceof Filter)),$filters);
			$name = key($function);
			$attributes = array_merge($function[$name]['attributes'],$additional);
			$function[$name]['attributes'] = empty($attributes) ? array($attributes) : $attributes;
			$this->handlers = array_merge($this->handlers,$function);
		endif;
	}
	public function removeEventHandler(object | callable $callback,? string $unique = null) : void {
		if(is_object($callback) and is_a($callback,'Closure') === false):
			$functions = Filter::getFunctions($callback,$unique);
			$keys = array_keys($functions);
			foreach($keys as $name):
				unset($this->handlers[$name]);
			endforeach;
		else:
			$function = Filter::getFunction($callback,$unique);
			$name = array_key_first($function);
			unset($this->handlers[$name]);
		endif;
	}
	/*
	public function waitForEvents(array $updates,callable $callback = null,int $timeout = 0) : object {
		$deferred = new DeferredFuture();
		$future = $deferred->getFuture();
		$filter = new Filter\Update(...$updates);
		$checker = function(object $update) use($deferred,$filter,$callback) : void {
			if($filter->apply($update)):
				if(is_null($callback) or async($callback,$update)->await()):
					$deferred->complete($update);
				endif;
			endif;
		};
		$this->addEventHandler($checker);
		$cancellation = $timeout > 0 ? new TimeoutCancellation($timeout) : null;
		$update = $future->await($cancellation);
		$this->removeEventHandler($checker);
		return $update;
	}
	*/
	public function fetchOneUpdate(array $updates,? callable $callback = null,float $timeout = 0) : object {
		$unique = uniqid('LiveProto');
		$deferredFuture = new DeferredFuture();
		$checker = function(object $update) use($deferredFuture,$callback,&$checker,$unique) : void {
			if(is_null($callback) or async($callback,$update)->await()):
				$deferredFuture->complete($update);
			endif;
			$this->removeEventHandler($checker,$unique);
		};
		$this->addEventHandler($checker,$unique,array(new Filter\Update(...$updates)));
		$deferredCancellation = new DeferredCancellation();
		return new class($updates,$checker,$deferredFuture,$deferredCancellation,$timeout) extends Instance {
			public function __construct(array $updates,protected Closure $checker,public object $deferredFuture,public object $deferredCancellation,protected float $timeout){
				parent::__construct(['updates'=>$updates,'timeout'=>$timeout]);
			}
			public function await() : mixed {
				$future = $this->deferredFuture->getFuture();
				$cancellation = $this->deferredCancellation->getCancellation();
				if($this->timeout > 0):
					EventLoop::delay($this->timeout,fn(string $id) : null => $this->cancel(new TimeoutException('Operation timed out')));
				endif;
				$update = $future->await($cancellation);
				return $update;
			}
			public function cancel(? Throwable $previous = null) : void {
				$this->deferredCancellation->cancel($previous);
			}
		};
	}
	public function applyUpdate(object $update) : void {
		if(isset($update->seq)):
			Logging::log('Updates','Checking SEQ of an update : '.$update->getClass(),0);
			$state = $this->state();
			$update->seq_start ??= $update->seq;
			if($update->seq_start === 0):
				Logging::log('Update Special Case','seq_start = 0',0);
				array_map($this->applyUpdate(...),$update->updates);
			elseif($state->seq + 1 === $update->seq_start):
				Logging::log('Update Accepted','local seq = '.$state->seq.' (+) 1 (===) seq_start = '.$update->seq_start,0);
				array_map($this->applyUpdate(...),$update->updates);
			elseif($state->seq + 1 > $update->seq_start):
				Logging::log('Update Skipped','local seq = '.$state->seq.' (+) 1 (>) seq_start = '.$update->seq_start,0);
				return;
			elseif($state->seq + 1 < $update->seq_start):
				Logging::log('Update Missed','local seq = '.$state->seq.' (+) 1 (<) seq_start = '.$update->seq_start,0);
				$this->recoveringGaps();
				$this->applyUpdate($update);
				return;
			endif;
			$state->date = $update->date;
			$state->seq = max($update->seq,$state->seq);
			return;
		endif;
		if(isset($update->qts)):
			Logging::log('Updates','Checking QTS of an update : '.$update->getClass(),0);
			$state = $this->state();
			$update->qts_count = 1;
			if($state->qts <= 0):
				$state->qts = $update->qts - $update->qts_count;
				Logging::log('Updates','Initializing qts = '.$state->qts,0);
			endif;
			if($state->qts + $update->qts_count === $update->qts):
				Logging::log('Update Accepted','local qts = '.$state->qts.' (+) qts count = '.$update->qts_count.' (===) qts = '.$update->qts,0);
				$state->qts = $update->qts;
			elseif($state->qts + $update->qts_count > $update->qts):
				Logging::log('Update Skipped','local qts = '.$state->qts.' (+) qts count = '.$update->qts_count.' (>) qts = '.$update->qts,0);
				return;
			elseif($state->qts + $update->qts_count < $update->qts):
				Logging::log('Update Missed','local qts = '.$state->qts.' (+) qts count = '.$update->qts_count.' (<) qts = '.$update->qts,0);
				$this->recoveringGaps();
				$this->applyUpdate($update);
				return;
			endif;
		endif;
		if(isset($update->pts,$update->pts_count)):
			Logging::log('Updates','Checking PTS of an update : '.$update->getClass(),0);
			$state = $this->state();
			/* what about channels ? */
			if($state->pts + $update->pts_count === $update->pts):
				Logging::log('Update Accepted','local pts = '.$state->pts.' (+) pts count = '.$update->pts_count.' (===) pts = '.$update->pts,0);
				$state->pts = $update->pts;
			elseif($state->pts + $update->pts_count > $update->pts):
				Logging::log('Update Skipped','local pts = '.$state->pts.' (+) pts count = '.$update->pts_count.' (>) pts = '.$update->pts,0);
				return;
			elseif($state->pts + $update->pts_count < $update->pts):
				Logging::log('Update Missed','local pts = '.$state->pts.' (+) pts count = '.$update->pts_count.' (<) pts = '.$update->pts,0);
				$this->recoveringGaps();
				$this->applyUpdate($update);
				return;
			endif;
		endif;
		Logging::log('Updates','Applying an update : '.$update->getClass(),0);
		try {
			$encryptedMessage = new Filter\Update('updateNewEncryptedMessage');
			if($encryptedMessage->apply($update)):
				$update->decrypted = $this->client->handle_encrypted_update($update);
			endif;
			$encryption = new Filter\Update('updateEncryption');
			if($encryption->apply($update)):
				if($update->chat instanceof \Tak\Liveproto\Tl\Types\Other\EncryptedChat):
					$this->client->finish_secret_chat_creation($update->chat);
				elseif($update->chat instanceof \Tak\Liveproto\Tl\Types\Other\EncryptedChatRequested):
					$this->client->accept_secret_chat($update->chat);
				elseif($update->chat instanceof \Tak\Liveproto\Tl\Types\Other\EncryptedChatDiscarded):
					$this->client->remove_secret($update->chat->id);
				endif;
			endif;
		} catch(\Throwable $error){
			Logging::log('Updates','An encrypted update was skipped ! '.$error->getMessage(),E_WARNING);
			return;
		}
		/* TODO : Add separate handlers to manage updates related to phone calls */
		switch($update->getClass()):
			case 'updateConfig':
				if(isset($this->client->config)):
					$this->client->config = $this->client->help->getConfig();
				endif;
				break;
			case 'updateDcOptions':
				if(isset($this->client->config)):
					$this->client->config->dc_options = $update->dc_options;
				endif;
				break;
			case 'updatePtsChanged':
				$this->state(reset : true);
				break;
			case 'updateChannelTooLong':
				$this->recoveringChannel(channel_id : $update->channel_id,pts : $update->pts);
				return;
			case 'updateMessageID':
				return;
		endswitch;
		$this->broadcastUpdate($update);
	}
	public function broadcastUpdate(object $update) : void {
		$update->setClient($this->client);
		foreach($this->handlers as $name => $handler):
			Logging::log('Updates','Handler “'.$handler['name'].'” : Starting parameters checks',0);
			$paramsCustom = array();
			foreach($handler['parameters'] as $parameter):
				$cloned = clone $update;
				$check = $parameter($cloned);
				if($check === false):
					Logging::log('Updates','Handler “'.$handler['name'].'” : Parameter check failed – skipping',0);
					continue 2;
				endif;
				$paramsCustom []= $cloned->is_custom;
			endforeach;
			Logging::log('Updates','Handler “'.$handler['name'].'” : Parameters checked , Starting attribute checks',0);
			if(empty($handler['attributes'])):
				$applies = array();
				goto run;
			endif;
			foreach($handler['attributes'] as $i => $attributes):
				Logging::log('Updates','Handler “'.$handler['name'].'”: Applying attributes – '.$i,0);
				$applies = array_map(fn(object $attribute) : mixed => $attribute->apply(clone $update),$attributes);
				if(empty($applies)):
					Logging::log('Updates','Handler “'.$handler['name'].'” : Events were not generated – '.$i,0);
					goto run;
				elseif(in_array(false,$applies) === false):
					Logging::log('Updates','Handler “'.$handler['name'].'” : Events generated – '.$i,0);
					foreach($applies as $i => $event):
						if($event instanceof Instance):
							$event->setClient($this->client);
						endif;
						if($event instanceof Events and isset($event->addBoundMethods)):
							$applies[$i] = $event->addBoundMethods($event);
						endif;
					endforeach;
					Logging::log('Updates','Handler “'.$handler['name'].'” : Added and initialized bound methods on the event – '.$i,0);
					goto run;
				else:
					Logging::log('Updates','Handler “'.$handler['name'].'” : Some attributes returned false – skipping – '.$i,0);
					continue;
				endif;
			endforeach;
			continue;
			run:
			if(empty($applies)):
				Logging::log('Updates','Handler “'.$handler['name'].'” : Dispatching original callback',0);
				$applies = array(clone $update);
			endif;
			Logging::log('Updates','Handler “'.$handler['name'].'” : Calling callback with events',0);
			$arguments = array_map(fn(mixed $apply,? bool $paramCustom) : mixed => ($paramCustom and ($apply instanceof Instance) === true and ($apply instanceof Events) === false) ? Events::copy($apply)->setClient($this->client) : $apply,$applies,$paramsCustom);
			async($handler['callback'],...$arguments)->catch(fn(\Throwable $error) : bool => error_log($error->getMessage()));
		endforeach;
	}
	public function &state(bool $reset = false) : object {
		if($reset or ($this->load->state instanceof \stdClass and $this->client->isAuthorized())):
			$this->load->state = $this->client->updates->getState();
		endif;
		return $this->load->state;
	}
	public function recoveringGaps() : void {
		$isLocked = isset($this->lock);
		$this->lock = $this->mutex->acquire();
		if($isLocked === false):
			$state = $this->state();
			$differences = $this->client->get_difference(pts : $state->pts,date : $state->date,qts : $state->qts);
			foreach($differences as $difference):
				$newState = $difference->state ?? $difference->intermediate_state;
				if(isset($difference->new_messages)):
					array_map(fn(object $newMessage) : mixed => $this->applyUpdate(new \Tak\Liveproto\Tl\Types\Other\UpdateNewMessage(['message'=>$newMessage])),$difference->new_messages);
				endif;
				if(isset($difference->new_encrypted_messages)):
					$start_qts = $newState->qts - count($difference->new_encrypted_messages);
					$fn = function(object $newEncryptedMessage) use(&$start_qts) : void {
						$this->applyUpdate(new \Tak\Liveproto\Tl\Types\Other\UpdateNewEncryptedMessage(['message'=>$newEncryptedMessage,'qts'=>++$start_qts]));
					};
					array_map($fn,$difference->new_encrypted_messages);
				endif;
				if(isset($difference->other_updates)):
					array_map($this->applyUpdate(...),$difference->other_updates);
				endif;
				$state->pts = $newState->pts;
				$state->qts = $newState->qts;
				$state->date = $newState->date;
				$state->seq = $newState->seq;
				break; // I didn't want to take them all
			endforeach;
		endif;
		if(isset($this->lock)):
			$lock = $this->lock;
			unset($this->lock);
			$lock->release();
		endif;
	}
	public function recoveringChannel(int $channel_id,? int $pts = null) : void {
		static $cache = array();
		$hash = md5(serialize($channel_id));
		if(is_null($pts)):
			if(key_exists($hash,$cache)):
				$pts = $cache[$hash];
			else:
				$pts = 1;
			endif;
		endif;
		$differences = $this->client->get_channel_difference(channel : $channel_id,pts : $pts);
		foreach($differences as $difference):
			if(isset($difference->new_messages)):
				array_map(fn(object $newChannelMessage) : mixed => $this->applyUpdate(new \Tak\Liveproto\Tl\Types\Other\UpdateNewChannelMessage(['message'=>$newChannelMessage])),$difference->new_messages);
			endif;
			if(isset($difference->other_updates)):
				array_map($this->applyUpdate(...),$difference->other_updates);
			endif;
			$cache[$hash] = $difference->pts;
		endforeach;
	}
	public function processUpdate(object $update) : void {
		switch($update->getClass()):
			# updatesTooLong#e317af7e = Updates; #
			case 'updatesTooLong':
				$this->recoveringGaps();
				break;
			# updateShortMessage#313bc7f8 flags:# out:flags.1?true mentioned:flags.4?true media_unread:flags.5?true silent:flags.13?true id:int user_id:long message:string pts:int pts_count:int date:int fwd_from:flags.2?MessageFwdHeader via_bot_id:flags.11?long reply_to:flags.3?MessageReplyHeader entities:flags.7?Vector<MessageEntity> ttl_period:flags.25?int = Updates; #
			# updateShortChatMessage#4d6deea5 flags:# out:flags.1?true mentioned:flags.4?true media_unread:flags.5?true silent:flags.13?true id:int from_id:long chat_id:long message:string pts:int pts_count:int date:int fwd_from:flags.2?MessageFwdHeader via_bot_id:flags.11?long reply_to:flags.3?MessageReplyHeader entities:flags.7?Vector<MessageEntity> ttl_period:flags.25?int = Updates; #
			case 'updateShortMessage':
			case 'updateShortChatMessage':
				# updateShortMessage#313bc7f8 flags:# out:flags.1?true mentioned:flags.4?true media_unread:flags.5?true silent:flags.13?true id:int user_id:long message:string pts:int pts_count:int date:int fwd_from:flags.2?MessageFwdHeader via_bot_id:flags.11?long reply_to:flags.3?MessageReplyHeader entities:flags.7?Vector<MessageEntity> ttl_period:flags.25?int = Updates; #
				if(isset($update->user_id)):
					$update->peer_id = new \Tak\Liveproto\Tl\Types\Other\PeerUser(['user_id'=>$update->user_id]);
				# updateShortChatMessage#4d6deea5 flags:# out:flags.1?true mentioned:flags.4?true media_unread:flags.5?true silent:flags.13?true id:int from_id:long chat_id:long message:string pts:int pts_count:int date:int fwd_from:flags.2?MessageFwdHeader via_bot_id:flags.11?long reply_to:flags.3?MessageReplyHeader entities:flags.7?Vector<MessageEntity> ttl_period:flags.25?int = Updates; #
				elseif(isset($update->chat_id,$update->from_id)):
					$update->peer_id = new \Tak\Liveproto\Tl\Types\Other\PeerChat(['chat_id'=>$update->chat_id]);
					$update->from_id = new \Tak\Liveproto\Tl\Types\Other\PeerUser(['user_id'=>$update->from_id]);
				endif;
				$message = $update->clone(new \Tak\Liveproto\Tl\Types\Other\Message,true);
				$newMessage = new \Tak\Liveproto\Tl\Types\Other\UpdateNewMessage(['message'=>$message,'pts'=>$update->pts,'pts_count'=>$update->pts_count]);
				$this->applyUpdate($newMessage);
				break;
			# updateShort#78d4dec1 update:Update date:int = Updates; #
			case 'updateShort':
				$this->applyUpdate($update->update);
				break;
			# updatesCombined#725b04c3 updates:Vector<Update> users:Vector<User> chats:Vector<Chat> date:int seq_start:int seq:int = Updates; #
			# updates#74ae4240 updates:Vector<Update> users:Vector<User> chats:Vector<Chat> date:int seq:int = Updates; #
			case 'updatesCombined':
			case 'updates':
				$this->saveAccessHash($update);
				$this->applyUpdate($update);
				break;
			# updateShortSentMessage#9015e101 flags:# out:flags.1?true id:int pts:int pts_count:int date:int media:flags.9?MessageMedia entities:flags.7?Vector<MessageEntity> ttl_period:flags.25?int = Updates; #
			case 'updateShortSentMessage':
				# I haven't received such an update yet, I'll add it whenever needed #
				/*
				$difference = $this->client->get_difference(pts : $update->pts - 1,date : $update->date,pts_limit : 1);
				$message = $difference[false]->new_messages[false];
				$update = new \Tak\Liveproto\Tl\Types\Other\UpdateNewMessage(['message'=>$message,'pts'=>$update->pts,'pts_count'=>$update->pts_count]);
				$this->applyUpdate($update);
				*/
				/*
				$newMessages = $this->recoveringGaps();
				# I don't know if there might be some interference in the line below or not #
				$message = $newMessages[false];
				$update = new \Tak\Liveproto\Tl\Types\Other\UpdateNewMessage(['message'=>$message,'pts'=>$update->pts,'pts_count'=>$update->pts_count]);
				$this->applyUpdate($update);
				*/
				Logging::log('Process Updates','I received updateShortSentMessage',E_NOTICE);
				$local_pts = intval($update->pts - $update->pts_count);
				$difference = $this->client->updates->getDifference(pts : $local_pts,date : $update->date,pts_limit : $update->pts_count,qts : 0);
				if(isset($difference->new_messages) and count($difference->new_messages) === $update->pts_count):
					Logging::log('Process Updates','updates.getDifference results accepted for updateShortSentMessage',0);
					$fn = function(object $newMessage) use(&$local_pts) : void {
						$this->applyUpdate(new \Tak\Liveproto\Tl\Types\Other\UpdateNewMessage(['message'=>$newMessage,'pts'=>++$local_pts,'pts_count'=>1]));
					};
					array_map($fn,$difference->new_messages);
				endif;
				break;
			default:
				Logging::log('Process Updates','Unknown update : '.$update->getClass(),E_ERROR);
		endswitch;
	}
	public function saveAccessHash(object $update) : void {
		$chats = $update->chats;
		$this->load->peers->setPeers(type : 'chats',peers : array_map(fn(mixed $peer) : array => array('id'=>$peer->id,'access_hash'=>$peer->access_hash),$chats));
		$users = $update->users;
		$this->load->peers->setPeers(type : 'users',peers : array_map(fn(mixed $peer) : array => array('id'=>$peer->id,'access_hash'=>$peer->access_hash),$users));
	}
	public function __debugInfo() : array {
		return array(
			'handlers'=>array_map(static fn(array $handler) : array => array_diff_key($handler,['callback'=>null]),$this->handlers)
		);
	}
}

?>