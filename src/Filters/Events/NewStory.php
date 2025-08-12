<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Filters\Events;

use Tak\Liveproto\Filters\Filter;

use Tak\Liveproto\Handlers\Events;

final class NewStory extends Filter {
	public function __construct(Filter ...$filters){
		$this->items = $filters;
	}
	public function apply(object $update) : object | bool {
		if($update instanceof \Tak\Liveproto\Tl\Types\Other\UpdateStory):
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
			return $event->get_input_peer(is_null($peer) ? $event->peer : $peer);
		};
		$event->getPeerId = function() use($event) : int {
			try {
				return $event->get_peer_id($event->peer);
			} catch(\Throwable){
				throw new \Exception('The update does not contain a valid peer id');
			}
		};
		$event->respond = function(string $message,mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			return $event->getClient()->messages->sendMessage($peer,$message,random_int(PHP_INT_MIN,PHP_INT_MAX),...$args);
		};
		$event->reply = function(string $message,array $reply_to = array(),mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			$args += ['reply_to'=>$event->inputReplyToStory($peer,$event->story->id,...$reply_to)];
			return $event->getClient()->messages->sendMessage($peer,$message,random_int(PHP_INT_MIN,PHP_INT_MAX),...$args);
		};
		$event->forward = function(mixed $peer,array $reply_to = array(),mixed ...$args) use($event) : object {
			$args += ['reply_to'=>(isset($reply_to['peer']) || isset($reply_to['story_id'])) ? $event->inputReplyToStory(...$reply_to) : $event->inputReplyToMessage(...$reply_to)];
			$to = $event->get_input_peer($peer);
			$peer = $event->getPeer();
			$media = $event->inputMediaStory(peer : $peer,id : $event->story->id);
			$message = strval($event->story->caption ?? null);
			return $event->getClient()->messages->sendMedia($to,$media,$message,random_int(PHP_INT_MIN,PHP_INT_MAX),...$args);
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
			return $event->getClient()->stories->sendReaction($peer,$event->story->id,...$args);
		};
		$event->getLink = function(mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			return $event->getClient()->stories->exportStoryLink($peer,$event->story->id,...$args);
		};
		$event->getStories = function(mixed ...$args) use($event) : object {
			$peer = $event->getPeer();
			return $event->getClient()->stories->getPeerStories($peer,...$args);
		};
		$event->type = $event->get_peer_type($event->peer)->getChatType();
		unset($event->addBoundMethods);
		return $event;
	}
}

?>