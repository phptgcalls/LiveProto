<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl;

use Tak\Liveproto\Tl\Methods\Account;

use Tak\Liveproto\Tl\Methods\Auth;

use Tak\Liveproto\Tl\Methods\Buttons;

use Tak\Liveproto\Tl\Methods\Dialog;

use Tak\Liveproto\Tl\Methods\Download;

use Tak\Liveproto\Tl\Methods\Entities;

use Tak\Liveproto\Tl\Methods\FileId;

use Tak\Liveproto\Tl\Methods\Inline;

use Tak\Liveproto\Tl\Methods\Peers;

use Tak\Liveproto\Tl\Methods\SecretChat;

use Tak\Liveproto\Tl\Methods\Upload;

use Tak\Liveproto\Tl\Methods\Users;

use Tak\Liveproto\Tl\Methods\Utilities;

use Tak\Liveproto\Utils\Errors;

use function Amp\async;

use function Amp\delay;

use function Amp\Future\await;

abstract class Caller {
	protected ? object $dhConfig = null;
	protected array $peersType = array();
	protected array $peersId = array();
	protected array $secretChats = array();

	use Account;
	use Auth;
	use Buttons;
	use Dialog;
	use Download;
	use Entities;
	use FileId;
	use Inline;
	use Peers;
	use SecretChat;
	use Upload;
	use Users;
	use Utilities;

	public function __get(string $property) : object {
		return new Properties($this,$property);
	}
	public function __call(string $name,array $arguments) : mixed {
		$other = new Properties($this,'other');
		return $other->$name(...$arguments);
	}
	public function __invoke(string $request,array $arguments) : mixed {
		$split = explode(str_contains($request,chr(46)) ? chr(46) : chr(47),$request);
		if(count($split) === 2):
			$name = $split[true];
			$space = $split[false];
		elseif(count($split) === 1):
			$name = $split[false];
			$space = 'other';
		else:
			throw new \Exception('Namespace ('.$request.') not found !');
		endif;
		$other = new Properties($this,$space);
		return $other->$name(...$arguments);
	}
}

final class Properties {
	public function __construct(private readonly object $parent,private readonly string $property){
	}
	public function __get(string $property) : mixed {
		if(property_exists($this->parent,$property)):
			$reflection = new \ReflectionClass($this->parent);
			$property = $reflection->getProperty($property);
			return $property->getValue($this->parent);
		else:
			# return new self($this->parent,$property);
			throw new \Exception('Undefined property: Client'.chr(58).chr(58).chr(36).$property);
		endif;
	}
	public function __call(string $name,array $arguments) : mixed {
		if(preg_match('~^(.+?)(?:_)?multiple$~i',$name,$match)):
			$name = $match[true];
			if(isset($arguments['responses'])):
				$responses = boolval($arguments['responses']);
				unset($arguments['responses']);
			else:
				$responses = false;
			endif;
			$processes = array();
			foreach($arguments as $argument):
				$processes []= async(fn(string $method) : mixed => call_user_func($method,$name,$argument + ['response'=>$responses]),__METHOD__);
			endforeach;
			$results = await($processes);
			ksort($results);
			return $results;
		endif;
		if($class = $this->createObject('Tak\\Liveproto\\Tl\\Functions\\'.ucfirst($this->property).'\\'.ucfirst($name))):
			$parameters = [
				'raw'=>[
					'func'=>boolval(...),
					'default'=>false
				],
				'response'=>[
					'func'=>boolval(...),
					'default'=>true
				],
				'timeout'=>[
					'func'=>floatval(...),
					'default'=>0
				],
				'floodwaitlimit'=>[
					'func'=>floatval(...),
					'default'=>0
				],
				/*
				'extra'=>[
					'func'=>null,
					'default'=>null // IDK but it's must be have a value... why ?!?
				],
				*/
			];
			$filtered = array();
			foreach($parameters as $key => $value):
				if(isset($arguments[$key])):
					$filtered[$key] = is_null($value['func']) ? $arguments[$key] : call_user_func($value['func'],$arguments[$key]);
					unset($arguments[$key]);
				else:
					$filtered[$key] = $value['default'];
				endif;
			endforeach;
			extract($filtered);
			if(isset($arguments['receiveupdates'])):
				if($arguments['receiveupdates'] === false):
					unset($arguments['receiveupdates']);
					$arguments['raw'] = true;
					$request = call_user_func(__METHOD__,$name,$arguments);
					return $this->parent->invokeWithoutUpdates($request,...$filtered);
				else:
					unset($arguments['receiveupdates']);
				endif;
			endif;
			if(isset($arguments['takeout'])):
				if($arguments['takeout'] === true):
					unset($arguments['takeout']);
					$arguments['raw'] = true;
					$request = call_user_func(__METHOD__,$name,$arguments);
					return $this->parent->invokeWithTakeout($this->takeoutid,$request,...$filtered);
				else:
					unset($arguments['takeout']);
				endif;
			endif;
			$request = new $class($arguments);
			if($raw):
				return $request;
			else:
				$binary = $request->stream();
				$this->sender->send($binary);
				try {
					$result = $response ? $this->sender->receive($binary,$timeout) : new \stdClass;
				} catch(Errors $error){
					$floodmax = max($this->settings->floodsleepthreshold,$floodwaitlimit);
					if($error->getCode() == 420 and $floodmax >= $error->getValue()):
						delay($error->getValue());
						$arguments['timeout'] = $timeout;
						$result = call_user_func(__METHOD__,$name,$arguments);
					else:
						throw $error;
					endif;
				}
				// if(is_null($extra) === false) $result->extra = $extra;
				return $result;
			endif;
		elseif($class = $this->createObject('Tak\\Liveproto\\Tl\\Types\\'.ucfirst($this->property).'\\'.ucfirst($name))):
			return new $class($arguments);
		elseif(method_exists($this->parent,$name)):
			$reflection = new \ReflectionClass($this->parent);
			$method = $reflection->getMethod($name);
			return $method->invoke($this->parent,...$arguments);
		else:
			throw new \Exception('Call to undefined function '.$name.'()');
		endif;
	}
	private function createObject(string $class) : object | false {
		return class_exists($class) ? new $class : false;
	}
}

?>