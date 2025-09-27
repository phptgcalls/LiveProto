<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Tl\Pagination;

use Tak\Liveproto\Attributes\Type;

use Tak\Attributes\Common\Vector;

use Tak\Attributes\Common\Is;

use Iterator;

trait Messages {
	protected function fetch_messages(
		string | int | null | object $peer = null,
		string | int | null | object $offset_peer = null,
		bool $unread_mentions = false,
		bool $unread_reactions = false,
		bool $recent_locations = false,
		bool $posts = false,
		bool $search = false,
		bool $saved = false,
		bool $scheduled = false,
		#[Vector(new Is('int'))] ? array $id = null,
		#[Type('MessagesFilter')] ? object $filter = null,
		? string $query = null,
		int | bool | null $reply_to = null,
		? int $shortcut_id = null,
		int $offset = 0,
		int $offset_id = 0,
		int $offset_date = 0,
		int $limit = 100,
		int $min_id = 0,
		int $max_id = 0,
		int $min_date = 0,
		int $max_date = 0,
		callable | array $hashgen = array(),
		mixed ...$args
	) : Iterator {
		$inputPeer = $this->get_input_peer($peer);
		$inputOffsetPeer = $this->get_input_peer($offset_peer);
		if($unread_mentions):
			$fetchResults = fn(int $offset,int $limit) : array => $this->messages->getUnreadMentions(...$args,peer : $inputPeer,offset_id : $offset_id,add_offset : $offset,limit : $limit,max_id : $max_id,min_id : $min_id)->messages;
		elseif($unread_reactions):
			$fetchResults = fn(int $offset,int $limit) : array => $this->messages->getUnreadReactions(...$args,peer : $inputPeer,offset_id : $offset_id,add_offset : $offset,limit : $limit,max_id : $max_id,min_id : $min_id)->messages;
		elseif($recent_locations):
			$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->getRecentLocations(...$args,peer : $inputPeer,limit : $limit,hash : $hash)->messages;
			$hashgen = array('id','edit_date');
		elseif($posts):
			$fetchResults = fn(int $offset,int $limit) : array => $this->channels->searchPosts(...$args,query : $query,offset_rate : $offset,offset_peer : $inputOffsetPeer,offset_id : $offset_id,limit : $limit)->messages;
		elseif($search):
			if(is_null($peer)):
				$fetchResults = fn(int $offset,int $limit) : array => $this->messages->searchGlobal(...$args,q : strval($query),filter : is_null($filter) ? $this->inputMessagesFilterEmpty() : $filter,min_date : $min_date,max_date : $max_date,offset_rate : $offset,offset_peer : $inputOffsetPeer,offset_id : $offset_id,limit : $limit)->messages;
			else:
				$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->search(...$args,peer : $inputPeer,q : strval($query),filter : is_null($filter) ? $this->inputMessagesFilterEmpty() : $filter,min_date : $min_date,max_date : $max_date,offset_id : $offset_id,add_offset : $offset,limit : $limit,max_id : $max_id,min_id : $min_id,hash : $hash)->messages;
			endif;
		elseif($scheduled):
			if(is_null($id)):
				$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->getScheduledHistory(...$args,peer : $inputPeer,hash : $hash)->messages;
				$hashgen = array('id','edit_date','date');
			else:
				$fetchResults = fn(int $offset,int $limit) : array => ($slice = array_slice($id,$offset,$limit)) ? $this->messages->getScheduledMessages(...$args,peer : $inputPeer,id : $slice)->messages : null;
			endif;
		elseif(is_int($reply_to)):
			$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->getReplies(...$args,peer : $inputPeer,msg_id : $reply_to,offset_id : $offset_id,offset_date : $offset_date,add_offset : $offset,limit : $limit,max_id : $max_id,min_id : $min_id,hash : $hash)->messages;
		elseif(is_null($shortcut_id) === false):
			$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->getQuickReplyMessages(...$args,shortcut_id : $shortcut_id,id : $id,hash : $hash)->messages;
			$hashgen = array('id','edit_date');
		elseif(is_null($id) === false):
			$id = array_map(fn(int $value) : object => $reply_to ? $this->inputMessageReplyTo(id : $value) : $this->inputMessageID(id : $value),$id);
			if(is_null($peer)):
				$fetchResults = fn(int $offset,int $limit) : array => ($slice = array_slice($id,$offset,$limit)) ? $this->messages->getMessages(...$args,id : $slice)->messages : null;
			else:
				$inputChannel = $this->get_input_channel($peer);
				$fetchResults = fn(int $offset,int $limit) : array => ($slice = array_slice($id,$offset,$limit)) ? $this->channels->getMessages(...$args,channel : $inputChannel,id : $slice)->messages : null;
			endif;
		elseif(is_null($peer) === false):
			if($saved):
				$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->getSavedHistory(...$args,peer : $inputPeer,offset_id : $offset_id,offset_date : $offset_date,add_offset : $offset,limit : $limit,max_id : $max_id,min_id : $min_id,hash : $hash)->messages;
			else:
				$fetchResults = fn(int $offset,int $limit,int $hash) : array => $this->messages->getHistory(...$args,peer : $inputPeer,offset_id : $offset_id,offset_date : $offset_date,add_offset : $offset,limit : $limit,max_id : $max_id,min_id : $min_id,hash : $hash)->messages;
			endif;
		else:
			$messages = $this->messages->searchSentMedia(...$args,q : strval($query),filter : is_null($filter) ? $this->inputMessagesFilterEmpty() : $filter,limit : 0x7fffffff)->messages;
			$fetchResults = fn(int $offset,int $limit) : array => array_slice($messages,$offset,$limit);
		endif;
		return new Pagination($fetchResults,$offset,$limit,$hashgen);
	}
}

?>