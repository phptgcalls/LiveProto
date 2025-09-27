<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Utils\Logging;

use function Amp\async;

trait Dialog {
	public function get_dialogs(
		int $limit = PHP_INT_MAX,
		int $id = 0,
		int $date = 0,
		string | int | null | object $peer = null,
		int $hash = 0,
		? true $pinned = null,
		? int $folder = null,
		? callable $callback = null
	) : array {
		$dialogs = array();
		do {
			$lastoffset = ['id'=>$id,'date'=>$date,'peer'=>$peer];
			$result = $this->messages->getDialogs(offset_date : $date,offset_id : $id,offset_peer : $this->get_input_peer($peer),limit : min($limit,100),hash : $hash,exclude_pinned : $pinned,folder_id : $folder);
			$count = isset($result->dialogs) ? count($result->dialogs) : 0;
			$limit -= $count;
			if(isset($result->messages)):
				$messages = array_reverse($result->messages);
				foreach($messages as $message):
					if($message instanceof \Tak\Liveproto\Tl\Types\Others\Message or $message instanceof \Tak\Liveproto\Tl\Types\Others\MessageService):
						$id = $message->id;
						$date = $message->date;
						$peer = $message->peer_id;
						break;
					endif;
				endforeach;
				if(is_null($callback) === false):
					if(async($callback(...),$result)->await() === false):
						break;
					endif;
				else:
					$dialogs []= $result;
				endif;
			endif;
			$newoffset = ['id'=>$id,'date'=>$date,'peer'=>$peer];
		} while($lastoffset !== $newoffset and isset($result->count) and $count > 0 and $limit > 0);
		return $dialogs;
	}
	public function get_difference(
		int $pts = 1,
		int $date = 1,
		int $qts = 1,
		? int $total_limit = 0x7fffffff,
		? int $pts_limit = null,
		? int $qts_limit = null,
		bool $deep = false
	) : \Generator {
		while(true):
			Logging::log('Difference','pts = '.$pts.' & date = '.$date.' & qts = '.$qts);
			try {
				$difference = $this->updates->getDifference(pts : $pts,date : $date,qts : $qts,pts_total_limit : $total_limit,pts_limit : $pts_limit,qts_limit : $qts_limit,timeout : 3);
			} catch(\Throwable $error){
				$difference = new \Tak\Liveproto\Tl\Types\Updates\DifferenceEmpty;
			}
			if($difference instanceof \Tak\Liveproto\Tl\Types\Updates\Difference):
				$pts = $difference->state->pts;
				$date = $difference->state->date;
				$qts = $difference->state->qts;
			elseif($difference instanceof \Tak\Liveproto\Tl\Types\Updates\DifferenceSlice):
				$pts = $difference->intermediate_state->pts;
				$date = $difference->intermediate_state->date;
				$qts = $difference->intermediate_state->qts;
			elseif($difference instanceof \Tak\Liveproto\Tl\Types\Updates\DifferenceTooLong):
				$pts = $deep ? $this->search_pts($pts,$difference->pts,$qts,$date) : $difference->pts;
				continue;
			elseif($difference instanceof \Tak\Liveproto\Tl\Types\Updates\DifferenceEmpty):
				break;
			else:
				throw new Exception('Unknown difference update !');
			endif;
			yield $difference;
		endwhile;
	}
	public function get_channel_difference(
		mixed $channel,
		? object $filter = null,
		int $pts = 1,
		int $limit = 0x7fffffff
	) : \Generator {
		$inputChannel = $this->get_input_peer($channel);
		while(true):
			Logging::log('Channel Difference','pts = '.$pts.' & limit = '.$limit.' & channel id = '.$this->get_peer_id($inputChannel));
			$difference = $this->updates->getChannelDifference(channel : $inputChannel,filter : is_null($filter) ? $this->channelMessagesFilterEmpty() : $filter,pts : $pts,limit : $limit,force : true);
			if($difference instanceof \Tak\Liveproto\Tl\Types\Updates\ChannelDifference):
				$pts = $difference->pts;
			elseif($difference instanceof \Tak\Liveproto\Tl\Types\Updates\ChannelDifferenceTooLong):
				if(isset($difference->dialog->pts) and is_null($difference->final)):
					$pts = $difference->dialog->pts;
					continue;
				else:
					break;
				endif;
			elseif($difference instanceof \Tak\Liveproto\Tl\Types\Updates\ChannelDifferenceEmpty):
				break;
			else:
				throw new Exception('Unknown channel difference update !');
			endif;
			yield $difference;
			if($difference->final) break;
		endwhile;
	}
	private function search_pts(
		int $bottom,
		int $top,
		int $qts,
		int $date
	) : int {
		Logging::log('Difference','Finding PTS...');
		while($bottom <= $top):
			$pts = ($bottom + $top) >> 1;
			try {
				$difference = $this->updates->getDifference(pts : $pts,date : $date,qts : $qts,pts_total_limit : 0x7fffffff,timeout : 3);
			} catch(\Throwable $error){
				Logging::log('Difference',$error->getMessage(),E_WARNING);
				$difference = new \Tak\Liveproto\Tl\Types\Updates\DifferenceTooLong;
			}
			if($difference instanceof \Tak\Liveproto\Tl\Types\Updates\DifferenceTooLong):
				$bottom = $pts + 1;
			else:
				$top = $pts - 1;
			endif;
		endwhile;
		return $bottom;
	}
}

?>