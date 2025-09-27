<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Crypto\Password;

use Tak\Liveproto\Attributes\Type;

trait Buttons {
	protected function click_button(
		#[Type('Message')] object $message,
		? int $i = null,
		? int $j = null,
		? string $text = null,
		? string $data = null,
		? string $query = null,
		? callable $filter = null,
		? string $password = null,
		? array $contact = null,
		? array $geo = null,
		string | int | null | object $user = null
	) : mixed {
		if($message instanceof \Tak\Liveproto\Tl\Types\Other\Message):
			if(is_object($message->reply_markup)):
				$button = $this->get_button($message->reply_markup,$i,$j,$text,$data,$query,$filter);
				$peer = $this->get_input_peer($message->peer_id);
				if($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButton):
					return $this->messages->sendMessage(peer : $peer,message : $button->text,random_id : random_int(PHP_INT_MIN,PHP_INT_MAX));
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonUrl):
					return @file_get_contents($button->url);
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonCallback):
					if($button->requires_password):
						$password = is_null($password) ? (isset($this->load->password) ? $this->load->password : null) : $password;
						if(is_null($password) === false):
							$account = $this->account->getPassword();
							$checker = new Password();
							$password = $checker->srp($account,$password);
						else:
							throw new \InvalidArgumentException('The password argument is required !');
						endif;
					else:
						$password = null;
					endif;
					return $this->messages->getBotCallbackAnswer(peer : $peer,msg_id : $message->id,data : $button->data,password : $password);
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonRequestPhone):
					if(is_null($contact) === false):
						if(isset($contact['phone'],$contact['firstname'])):
							$contact = $this->inputMediaContact(phone_number : strval($contact['phone']),first_name : strval($contact['firstname']),last_name : strval(isset($contact['lastname']) ? $contact['lastname'] : null),vcard : strval(isset($contact['vcard']) ? $contact['vcard'] : null));
						else:
							throw new \InvalidArgumentException('The contact argument should be an array containing phone and firstname ( lastname & vcard optional ) !');
						endif;
					else:
						$me = $this->get_me();
						$contact = $this->inputMediaContact(phone_number : $me->phone,first_name : $me->first_name,last_name : strval($me->last_name),vcard : strval(null));
					endif;
					return $this->messages->sendMedia(peer : $peer,media : $contact,message : $button->text,random_id : random_int(PHP_INT_MIN,PHP_INT_MAX));
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonRequestGeoLocation):
					if(is_null($geo) === false):
						if(isset($geo['lat'],$geo['long'])):
							$geo = $this->inputMediaGeoPoint(geo_point : $this->inputGeoPoint(lat : floatval($geo['lat']),long : floatval($geo['long'])));
						else:
							throw new \InvalidArgumentException('The geo argument should be an array containing lat and long !');
						endif;
					else:
						throw new \InvalidArgumentException('The geo argument is required !');
					endif;
					return $this->messages->sendMedia(peer : $peer,media : $geo,message : $button->text,random_id : random_int(PHP_INT_MIN,PHP_INT_MAX));
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonSwitchInline):
					if($button->same_peer):
						$bot = is_int($message->via_bot_id) ? $this->get_input_peer($message->via_bot_id) : $peer;
					elseif(is_null($user) === false):
						$bot = is_int($message->via_bot_id) ? $this->get_input_peer($message->via_bot_id) : $peer;
						$peer = $this->get_input_peer($user);
					else:
						throw new \InvalidArgumentException('The user argument is required !');
					endif;
					return $this->inline_query(bot : $bot,query : $button->query,peer : $peer);
					# return $this->messages->startBot(bot : $this->get_input_peer($message->via_bot_id),peer : $peer,start_param : $button->query,random_id : random_int(PHP_INT_MIN,PHP_INT_MAX));
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonGame):
					return $this->messages->getBotCallbackAnswer(peer : $peer,msg_id : $message->id,game : true);
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonRequestPeer):
					if(is_null($user) === false):
						$requested = $this->get_input_peer($user);
					else:
						throw new \InvalidArgumentException('The user argument is required !');
					endif;
					return $this->messages->sendBotRequestedPeer(peer : $peer,msg_id : $message->id,button_id : $button->button_id,requested_peer : $requested);
				elseif($button instanceof \Tak\Liveproto\Tl\Types\Other\KeyboardButtonCopy):
					return $button->copy_text;
				else:
					throw new \Exception('Unsupported button type !');
				endif;
			else:
				throw new \InvalidArgumentException('Your message does not contain reply markup !');
			endif;
		else:
			throw new \InvalidArgumentException('The message is invalid !');
		endif;
	}
	protected function get_button(
		#[Type('ReplyMarkup')] object $reply_markup,
		? int $i = null,
		? int $j = null,
		? string $text = null,
		? string $data = null,
		? string $query = null,
		? callable $filter = null
	) : object {
		$index = (is_null($i) === false and is_null($j)) ? $i : null;
		$x = 0;
		$y = 0;
		if($reply_markup instanceof \Tak\Liveproto\Tl\Types\Other\ReplyKeyboardMarkup or $reply_markup instanceof \Tak\Liveproto\Tl\Types\Other\ReplyInlineMarkup):
			foreach($reply_markup->rows as $row):
				foreach($row->buttons as $button):
					if(is_null($index) === false and $index === ($x + $y)):
						return $button;
					elseif($i === $x and $j === $y):
						return $button;
					elseif(is_null($text) === false and $button->text === $text):
						return $button;
					elseif(is_null($data) === false and $button->data === $data):
						return $button;
					elseif(is_null($query) === false and $button->query === $query):
						return $button;
					elseif(is_null($filter) === false and $filter($button)):
						return $button;
					endif;
					$y++;
				endforeach;
				$x++;
			endforeach;
		else:
			throw new \InvalidArgumentException('The reply markup must be an object of replyKeyboardMarkup / replyInlineMarkup !');
		endif;
		throw new \Exception('The button you wanted was not found !');
	}
}

?>