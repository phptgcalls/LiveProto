<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Filters\Events;

use Tak\Liveproto\Filters\Filter;

use Tak\Liveproto\Handlers\Events;

use Tak\Liveproto\Utils\StringTools;

final class Messages extends Filter {
	public function __construct(Filter ...$filters){
		$this->items = $filters;
	}
	public function apply(object $update) : object | bool {
		if(isset($update->message) and $update->message instanceof \Tak\Liveproto\Tl\Types\Other\Message):
			$applies = array_map(fn($filter) : mixed => $filter->apply($update),$this->items);
			$event = Events::copy($update);
			$event->addBoundMethods = $this->boundMethods(...);
			return in_array(false,$applies) ? false : $event;
		else:
			return false;
		endif;
	}
	private function boundMethods(object $event) : object {
		$event->getPeer = function(mixed $peer = null) use($event) : object {
			return $event->get_input_peer(is_null($peer) ? $event->message->peer_id : $peer);
		};
		$event->getPeerId = function() use($event) : int {
			try {
				return $event->get_peer_id($event->message->peer_id);
			} catch(\Throwable){
				throw new \Exception('The update does not contain a valid peer id');
			}
		};
		$event->getReply = function() use($event) : object | false {
			if($event->message->reply_to instanceof \Tak\Liveproto\Tl\Types\Other\MessageReplyHeader):
				$messages = ($event->message->peer_id instanceof \Tak\Liveproto\Tl\Types\Other\PeerUser ? $event->getClient()->messages->getMessages(array($event->getClient()->inputMessageID($event->message->reply_to->reply_to_msg_id))) : $event->getClient()->channels->getMessages($event->getPeer(),array($event->getClient()->inputMessageID($event->message->reply_to->reply_to_msg_id))));
				# messages.messagesNotModified#74535f21 count:int = messages.Messages; #
				return isset($messages->messages) ? end($messages->messages) : $event;
			elseif($event->message->reply_to instanceof \Tak\Liveproto\Tl\Types\Other\MessageReplyStoryHeader):
				$stories = $event->getClient()->stories->getStoriesByID($event->get_input_peer($event->message->reply_to->user_id),array($event->message->reply_to->story_id));
				return end($stories->stories);
			else:
				return false;
			endif;
		};
		$event->respond = function(string $message,mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			return $event->getClient()->messages->sendMessage($peer,$message,random_int(PHP_INT_MIN,PHP_INT_MAX),...$args);
		};
		$event->reply = function(string $message,array $reply_to = array(),mixed ...$args) use($event) : object {
			$args += ['reply_to'=>$event->inputReplyToMessage($event->message->id,...$reply_to)];
			$peer = $event->getPeer();
			return $event->getClient()->messages->sendMessage($peer,$message,random_int(PHP_INT_MIN,PHP_INT_MAX),...$args);
		};
		$event->forward = function(mixed $peer,array $reply_to = array(),mixed ...$args) use($event) : object {
			$args += ['reply_to'=>(isset($reply_to['peer']) || isset($reply_to['story_id'])) ? $event->inputReplyToStory(...$reply_to) : $event->inputReplyToMessage(...$reply_to)];
			$to = $event->get_input_peer($peer);
			$peer = $event->getPeer();
			return $event->getClient()->messages->forwardMessages($peer,array($event->message->id),array(random_int(PHP_INT_MIN,PHP_INT_MAX)),$to,...$args);
		};
		$event->edit = function(? string $message = null,? object $media = null,mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			$args += ['message'=>$message,'media'=>$media];
			return $event->getClient()->messages->editMessage($peer,$event->message->id,...$args);
		};
		$event->pin = function(mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			$args += ['unpin'=>null];
			return $event->getClient()->messages->updatePinnedMessage($peer,$event->message->id,...$args);
		};
		$event->unpin = function(bool $all = false,mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			if($all === true):
				return $event->getClient()->messages->unpinAllMessages($peer,...$args);
			else:
				$args += ['unpin'=>true];
				return $event->getClient()->messages->updatePinnedMessage($peer,$event->message->id,...$args);
			endif;
		};
		$event->delete = function(? true $revoke = null) use($event) : object {
			return $event->message->peer_id instanceof \Tak\Liveproto\Tl\Types\Other\PeerUser ? $event->getClient()->messages->deleteMessages(array($event->message->id),$revoke) : $event->getClient()->channels->deleteMessages($event->getPeer(),array($event->message->id));
		};
		$event->reaction = function(string | int | array | null $reaction,mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			if(is_null($reaction)):
				$reaction = $event->reactionEmpty();
			elseif(is_string($reaction)):
				$reaction = array($event->reactionEmoji($reaction));
			elseif(is_int($reaction)):
				$reaction = array($event->reactionCustomEmoji($reaction));
			elseif(is_array($reaction)):
				$reaction = array_map(fn(string | int | null $emoji) : object => is_string($emoji) ? $event->reactionEmoji($emoji) : (is_int($emoji) ? $event->reactionCustomEmoji($emoji) : $event->reactionEmpty()),$reaction);
			endif;
			$args += ['reaction'=>$reaction];
			return $event->getClient()->messages->sendReaction($peer,$event->message->id,...$args);
		};
		$event->screenshot = function(array $reply_to = array()) use($event) : object {
			$reply_to = $event->inputReplyToMessage($event->message->id,...$reply_to);
			$peer = $event->getPeer();
			return $event->getClient()->messages->sendScreenshotNotification($peer,$reply_to,random_int(PHP_INT_MIN,PHP_INT_MAX));
		};
		$event->block = function(mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			return $event->getClient()->contacts->block($peer,...$args);
		};
		$event->unblock = function(mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			return $event->getClient()->contacts->unblock($peer,...$args);
		};
		$event->download = function(string $path,mixed ...$args) use($event) : string {
			if(isset($event->message->media) === false):
				throw new \Exception('The message does not contain a media');
			else:
				return $event->getClient()->download_media($path,$event->message->media,...$args);
			endif;
		};
		$event->click = function(mixed ...$args) use($event) : mixed {
			if($event->message->reply_markup === null):
				throw new \Exception('The message does not contain a reply markup');
			else:
				return $event->getClient()->click_button($event->message,...$args);
			endif;
		};
		$event->message->format = function() use($event) : array {
			return $event->getClient()->format_entities($event->message->message,$event->message->entities);
		};
		$event->message->type = match(true){
			$event->message->peer_id instanceof \Tak\Liveproto\Tl\Types\Other\PeerChannel => ($event->message->post ? 'channel' : 'supergroup'),
			$event->message->peer_id instanceof \Tak\Liveproto\Tl\Types\Other\PeerUser => 'private',
			$event->message->peer_id instanceof \Tak\Liveproto\Tl\Types\Other\PeerChat => 'group',
			default => $event->get_peer_type($event->message->peer_id)->getChatType()
		};
		unset($event->addBoundMethods);
		return $event;
	}
}

?>