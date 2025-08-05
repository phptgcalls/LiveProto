<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Database;

use Tak\Liveproto\Enums\Authentication;

use Tak\Liveproto\Utils\Settings;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Tools;

use Amp\Mysql\MysqlConfig;

use function Amp\File\isFile;

use function Amp\File\read;

use function Amp\File\write;

use function Amp\File\getSize;

use SQLite3;

final class Session {
	private object $content;
	private array $servers = [['ip'=>'149.154.175.60','ipv6'=>'2001:0b28:f23d:f001:0000:0000:0000:000a','port'=>443,'dc'=>1],['ip'=>'149.154.167.41','ipv6'=>'2001:067c:04e8:f002:0000:0000:0000:000a','port'=>443,'dc'=>2],['ip'=>'149.154.175.100','ipv6'=>'2001:0b28:f23d:f003:0000:0000:0000:000a','port'=>443,'dc'=>3],['ip'=>'149.154.167.91','ipv6'=>'2001:067c:04e8:f004:0000:0000:0000:000a','port'=>443,'dc'=>4],['ip'=>'91.108.56.180','ipv6'=>'2001:0b28:f23f:f005:0000:0000:0000:000a','port'=>443,'dc'=>5]];
	private array $testservers = [['ip'=>'149.154.175.10','ipv6'=>'2001:b28:f23d:f001:0000:0000:0000:000e','port'=>80,'dc'=>1],['ip'=>'149.154.167.40','ipv6'=>'2001:67c:4e8:f002:0000:0000:0000:000e','port'=>80,'dc'=>2],['ip'=>'149.154.175.117','ipv6'=>'2001:b28:f23d:f003:0000:0000:0000:000e','port'=>80,'dc'=>3]];
	public readonly bool $ipv6;
	public readonly bool $testmode;
	public readonly int $dc;
	public readonly float $savetime;
	public readonly string $server;
	public readonly string $username;
	public readonly string $password;
	public readonly string $database;
	private object $mysql;
	private object $sqlite;

	public function __construct(public readonly string | null $name,private string | null $mode,Settings $settings){
		$this->ipv6 = is_bool($settings->ipv6) ? $settings->ipv6 : false;
		$this->testmode = is_bool($settings->testmode) ? $settings->testmode : false;
		$this->dc = is_int($settings->dc) ? $settings->dc : 0;
		$this->savetime = is_numeric($settings->savetime) ? $settings->savetime : 3;
		$this->server = is_string($settings->server) ? $settings->server : 'localhost';
		$this->username = is_string($settings->username) ? $settings->username : (string) null;
		$this->password = is_string($settings->password) ? $settings->password : (string) null;
		$this->database = is_string($settings->database) ? $settings->database : $this->username;
		register_shutdown_function($this->save(...));
	}
	public function generate() : object {
		if($this->dc > 0):
			if($this->testmode):
				if($this->dc <= count($this->testservers)):
					$server = $this->testservers[$this->dc - 1];
				else:
					throw new \Exception('The ID of the test data center must be less than '.count($this->testservers));
				endif;
			else:
				if($this->dc <= count($this->servers)):
					$server = $this->servers[$this->dc - 1];
				else:
					throw new \Exception('The ID of the data center must be less than '.count($this->servers));
				endif;
			endif;
		else:
			if($this->testmode):
				$server = $this->testservers[array_rand($this->testservers)];
			else:
				$server = $this->servers[array_rand($this->servers)];
			endif;
		endif;
		return new Content(['id'=>Helper::generateRandomLong(),'api_id'=>0,'api_hash'=>(string) null,'dc'=>$server['dc'],'ip'=>($this->ipv6 ? $server['ipv6'] : $server['ip']),'port'=>$server['port'],'auth_key'=>new \stdClass,'salt'=>0,'sequence'=>0,'time_offset'=>0,'last_msg_id'=>0,'logout_tokens'=>[],'peers'=>new CachedPeers($this->name),'state'=>(object) array('pts'=>1,'qts'=>-1,'date'=>1,'seq'=>0),'step'=>Authentication::NEEDAUTHENTICATION],$this->savetime);
	}
	public function load() : object {
		if(isset($this->content)):
			return $this->content;
		elseif(is_null($this->name)):
			$this->content = $this->generate();
			$this->content->setSession($this);
			return $this->content;
		else:
			switch(strtolower(strval($this->mode))):
				case 'string':
					$file = '.'.DIRECTORY_SEPARATOR.$this->name.'.session';
					if(isFile($file) and getSize($file)):
						$this->content = unserialize(gzinflate(base64_decode(read($file))))->setSession($this);
					else:
						$data = serialize($this->generate());
						write($file,base64_encode(gzdeflate($data)));
						$this->content = unserialize($data)->setSession($this);
					endif;
					break;
				case 'sqlite':
					$this->sqlite = new SQLite3($this->name.'.db');
					break;
				case 'mysql':
					if(empty($this->server)):
						throw new \Exception('Server parameter for mysql database is empty !');
					elseif(empty($this->username)):
						throw new \Exception('Username parameter for mysql database is empty !');
					elseif(empty($this->password)):
						throw new \Exception('Password parameter for mysql database is empty !');
					elseif(empty($this->database)):
						throw new \Exception('Database parameter for mysql database is empty !');
					else:
						$config = MysqlConfig::fromAuthority($this->server,$this->username,$this->password,$this->database);
						$this->mysql = new MySQL($config);
						if($this->mysql->init($this->name)):
							$content = $this->generate();
						else:
							$content = new Content(Tools::marshal($this->mysql->get($this->name)),$this->savetime);
						endif;
						$this->content = $content->setSession($this);
						$this->content->peers->init($this->mysql);
						$this->save();
					endif;
					break;
				case 'text':
					$this->content = unserialize(gzinflate(base64_decode($this->name)))->setSession($this);
					break;
				default:
					throw new \Exception('The database mode is invalid ! It must be one of the modes of string , sqlite , mysql , text !');
			endswitch;
		endif;
		return $this->content;
	}
	public function save() : void {
		if(is_null($this->name) === false and $this->mode !== 'text'):
			switch($this->mode):
				case 'string':
					$file = '.'.DIRECTORY_SEPARATOR.$this->name.'.session';
					if(isFile($file)):
						write($file,base64_encode(gzdeflate(serialize($this->content))));
					endif;
					break;
				case 'sqlite':
					break;
				case 'mysql':
					$data = Tools::marshal($this->content->toArray());
					array_walk($data,fn(mixed $value,string $key) : mixed => $this->mysql->set($this->name,$key,$value,Tools::inferType($value)));
					break;
				default:
					throw new \Exception('The database mode is invalid ! It must be one of the modes of string , sqlite , mysql , text !');
			endswitch;
		endif;
	}
	public function getStringSession() : string {
		return base64_encode(gzdeflate(serialize($this->content)));
	}
	public function getNewMsgId() : int {
		$now = microtime(true) + $this->content['time_offset'];
		$nanoseconds = intval(($now - intval($now)) * 1e9);
		$newMsgId = (intval($now) << 32) | ($nanoseconds << 2);
		if($this->content['last_msg_id'] >= $newMsgId) $newMsgId = $this->content['last_msg_id'] + 4;
		$this->content['last_msg_id'] = $newMsgId;
		return $newMsgId;
	}
	public function generateSequence() : int {
		$result = $this->content['sequence'] * 2 + 1;
		$this->content['sequence'] += 1;
		return $result;
	}
	public function updateTimeOffset(int $correctMsgId) : int {
		$old = $this->content['time_offset'];
		$now = time();
		$correct = $correctMsgId >> 32;
		$this->content['time_offset'] = $correct - $now;
		if($this->content['time_offset'] !== $old) $this->content['last_msg_id'] = 0;
		return $this->content['time_offset'];
	}
	public function __debugInfo() : array {
		return array(
			'content'=>isset($this->content) ? $this->content : new \stdClass,
			'ipv6'=>$this->ipv6,
			'testmode'=>$this->testmode,
			'dc'=>$this->dc,
			'savetime'=>$this->savetime,
			'server'=>$this->server,
			'username'=>$this->username,
			'password'=>$this->password,
			'database'=>$this->database
		);
	}
	public function __clone() : void {
		$this->content = clone $this->content;
	}
	public function __sleep() : array {
		return array('ipv6','testmode','dc','savetime','server','username','password','database');
	}
	public function __wakeup() : void {
		register_shutdown_function($this->save(...));
	}
}

?>