<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network;

use Tak\Liveproto\Tl\Caller;

use Tak\Liveproto\Utils\Tools;

use Tak\Liveproto\Utils\Settings;

use Tak\Liveproto\Utils\Logging;

use Tak\Liveproto\Ipc\SessionLocker;

use Tak\Liveproto\Ipc\SignalHandler;

use Tak\Liveproto\Database\Session;

use Tak\Liveproto\Database\Content;

use Tak\Liveproto\Handlers\Updates;

use Tak\Liveproto\Enums\Authentication;

use Tak\Liveproto\Filters\Filter;

use Revolt\EventLoop;

use Amp\Sync\LocalMutex;

use Stringable;

use function Amp\File\read;

final class Client extends Caller implements Stringable {
	protected Session $session;
	protected Content $load;
	protected TcpTransport $transport;
	protected Sender $sender;
	protected LocalMutex $mutex;
	public readonly Updates $handler;
	protected ? object $locker;
	public array $dcoptions;
	public object $config;
	public bool $connected = false;
	public int $takeoutid = 0;

	public function __construct(string | null $resourceName,string | null $storageDriver,public Settings $settings){
		if(is_null($resourceName) === false and empty($resourceName)):
			throw new \InvalidArgumentException('ResourceName cannot be an empty string value');
		endif;
		if(is_null($resourceName) !== is_null($storageDriver)):
			throw new \InvalidArgumentException('The `resourceName` and `storageDriver` parameters must both be null or both have string values');
		endif;
		new Logging($settings);
		$this->locker = in_array($storageDriver,['text',null]) ? null : ($settings->getHotReload ? new SignalHandler($resourceName,$this) : new SessionLocker($resourceName));
		$this->session = new Session($resourceName,$storageDriver,$settings);
		$this->load = $this->session->load();
		$this->handler = new Updates($this,$this->session);
		if(is_int($this->load->api_id) and $this->load->api_id > 0):
			Logging::log('Client','I used the saved Api Id !',0);
		else:
			$this->load->api_id = $settings->getApiId();
		endif;
		if(is_string($this->load->api_hash) and empty($this->load->api_hash) === false):
			Logging::log('Client','I used the saved Api Hash !',0);
		else:
			$this->load->api_hash = $settings->getApiHash();
		endif;
		$this->mutex = new LocalMutex;
		$this->dcoptions = array(new \Tak\Liveproto\Tl\Types\Other\DcOption(['id'=>$this->load->dc,'ip_address'=>$this->load->ip,'port'=>$this->load->port,'client'=>$this,'expires_at'=>0]));
	}
	public function connect(bool $reconnect = false,bool $reset = false) : void {
		if($reconnect):
			$this->disconnect();
		endif;
		Logging::log('Client','Connect to IP : '.$this->load->ip,0);
		$this->transport = new TcpTransport($this->load->ip,$this->load->port,$this->load->dc,$this->settings->protocol,$this->settings->proxy);
		if($this->load->auth_key instanceof \stdClass or $reset):
			$connect = new Connect($this->transport,$this->session);
			list($this->load->auth_key,$this->load->time_offset,$this->load->salt) = $connect->authentication($this->load->dc,$this->session->testmode);
		endif;
		$this->sender = new Sender($this->transport,$this->session,$this->handler);
		$getConfig = $this->help->getConfig(raw : true);
		$query = $this->initConnection(api_id : $this->load->api_id,device_model : $this->settings->devicemodel,system_version : $this->settings->systemversion,app_version : $this->settings->appversion,system_lang_code : $this->settings->systemlangcode,lang_pack : $this->settings->langpack,lang_code : $this->settings->langcode,query : $getConfig,params : $this->settings->params,raw : true);
		if($this->settings->receiveupdates === false):
			$query = $this->invokeWithoutUpdates(query : $query,raw : true);
		endif;
		$this->config = $this->invokeWithLayer(layer : $this->layer(),query : $query);
		$this->connected = true;
	}
	public function setDC(string $ip,int $port,int $id) : void {
		list($this->load->ip,$this->load->port,$this->load->dc) = func_get_args();
	}
	public function changeDC(int $dcid) : void {
		Logging::log('Client','Try change dc ...',0);
		foreach($this->config->dc_options as $dc):
			if($dc->ipv6 === $this->settings->ipv6 and $dc->id === $dcid and $dc->media_only === false and $dc->cdn === false):
				$this->removeDC($this->load->ip);
				$this->setDC($dc->ip_address,$dc->port,$dc->id);
				Logging::log('Client','New IP : '.$dc->ip_address,0);
				$this->connect(reconnect : true,reset : true);
				break;
			endif;
		endforeach;
	}
	public function switchDC(? int $dcid = null,bool $cdn = false,bool $media = false,bool $next = false,int $expires_in = 0) : self {
		if($this->load->dc === $dcid and $cdn === false and $next === false and $expires_in === 0):
			Logging::log('Client','There is no need to switch the data center , we use the same current data center',0);
			return $this;
		endif;
		Logging::log('Client','Try switch dc ...',0);
		$lock = $this->mutex->acquire();
		try {
			foreach($this->config->dc_options as $dc):
				if($dc->ipv6 === $this->settings->ipv6 and (is_null($dcid) or $dc->id === $dcid) and ($media === true or $dc->media_only === $media) and $dc->cdn === $cdn):
					if($next === false or ($next === true and in_array($dc->ip_address,array_column($this->dcoptions,'ip_address')) === false)):
						Logging::log('Client','Switch IP : '.$dc->ip_address,0);
						$is_authorized = $this->checkAuthorization($dcid);
						if($is_authorized === false):
							$authorization = $this->auth->exportAuthorization(dc_id : $dcid);
						endif;
						$expires_at = intval($expires_in > 0 ? time() + $expires_in : 0);
						$availableClients = $this->getAuthorizations(ip_address : $dc->ip_address,expires_at : $expires_at);
						if(empty($availableClients)):
							$this->dcoptions []= $dc;
							$dc->expires_at = $expires_at;
							$client = clone $this;
							$dc->client = $client;
						else:
							Logging::log('Client','I used old built clients ...',0);
							$client = current($availableClients)->client;
						endif;
						if($is_authorized === false):
							$importAuthorization = $client->auth->importAuthorization(id : $authorization->id,bytes : $authorization->bytes,raw : true);
							$query = $client->initConnection(api_id : $client->load->api_id,device_model : $client->settings->devicemodel,system_version : $client->settings->systemversion,app_version : $client->settings->appversion,system_lang_code : $client->settings->systemlangcode,lang_pack : $client->settings->langpack,lang_code : $client->settings->langcode,query : $importAuthorization,params : $this->settings->params,raw : true);
							if($client->receiveupdates === false):
								$query = $client->invokeWithoutUpdates(query : $query,raw : true);
							endif;
							$client->invokeWithLayer(layer : $client->layer(),query : $query);
							$client->connected = true;
						endif;
						return $client;
					endif;
				endif;
			endforeach;
		} catch(\Throwable $error){
			Logging::log('Client',$error->getMessage(),E_NOTICE);
		} finally {
			EventLoop::queue($lock->release(...));
		}
		throw new \Exception('There is a problem in creating the client for DC id '.$dcid.' !');
	}
	public function getTemp(int $expires_in) : self {
		static $client;
		Logging::log('Client','Try get temp ...',0);
		if($expires_in > 0):
			$client = $this->switchDC(dcid : $this->load->dc,expires_in : $expires_in);
			$reflection = new \ReflectionClass($client);
			$temp = $reflection->getProperty('load')->getValue($client);
			$sender = $reflection->getProperty('sender')->getValue($client);
			$this->sender->bindTempAuthKey(sender : $sender,temp_auth_key_id : $temp->auth_key->id,expires_at : $temp->auth_key->expires_at);
			return $client;
		elseif(isset($client)):
			return $client;
		else:
			throw new \Exception('The $expires_in must be greater than zero seconds for generate new temp auth !');
		endif;
	}
	public function checkAuthorization(int $dcid) : bool {
		return in_array($dcid,array_column($this->dcoptions,'id'));
	}
	public function getAuthorizations(mixed ...$filters) : array {
		return array_filter($this->dcoptions,fn(object $dcoption) : bool => array_intersect_assoc($dcoption->toArray(),$filters) == $filters);
	}
	public function isAuthorized() : bool {
		return boolval($this->load->step === Authentication::LOGIN);
	}
	public function getStep() : Authentication {
		return $this->load->step;
	}
	public function removeDC(string $ip) : void {
		$lock = $this->mutex->acquire();
		try {
			$this->dcoptions = array_filter($this->dcoptions,fn(object $dcoption) : bool => $dcoption->ip_address !== $ip);
		} finally {
			EventLoop::queue($lock->release(...));
		}
	}
	public function layer(bool $secret = false) : int {
		$layer = read(dirname(__DIR__).DIRECTORY_SEPARATOR.'Tl'.DIRECTORY_SEPARATOR.($secret ? 'secret.tl' : 'api.tl'));
		if(preg_match('~\/\/\sLAYER\s(?<layer>\d+)~i',$layer,$match)):
			return intval($match['layer']);
		else:
			throw new \Exception('The desired layer was not found !');
		endif;
	}
	public function registerFilteredFunctions() : void {
		$functions = get_defined_functions();
		foreach($functions['user'] as $function):
			$reflection = new \ReflectionFunction($function);
			$attributes = $reflection->getAttributes(Filter::class);
			if(empty($attributes) === false):
				$this->addHandler($function);
			endif;
		endforeach;
	}
	public function addHandler(object | callable $callback,? string $unique = null,Filter ...$filters) : void {
		$this->handler->addEventHandler($callback,$unique,...$filters);
	}
	public function removeHandler(object | callable $callback,? string $unique = null) : void {
		$this->handler->removeEventHandler($callback,$unique);
	}
	public function fetchUpdate(array $updates,? callable $callback = null,float $timeout = 0) : object {
		return $this->handler->fetchOneUpdate($updates,$callback,$timeout);
	}
	public function start() : void {
		if($this->connected === true):
			$this->disconnect();
		endif;
		$lock = is_null($this->locker) ? null : $this->locker->tryLock();
		if($this->connected === false):
			$this->connect();
		endif;
		if(Tools::isCli()):
			if($this->load->step === Authentication::NEEDAUTHENTICATION):
				$input = Tools::readLine('Please enter your phone number ( Or your bot token , you can give it from @BotFather ) : ');
				if(str_contains($input,chr(58))):
					$this->sign_in(token : $input);
				else:
					$this->send_code(phone_number : preg_replace('/[^\d]/',strval(null),$input));
				endif;
			endif;
			if($this->load->step === Authentication::NEEDCODE):
				$input = Tools::readLine('Please enter that code you received : ');
				try {
					$this->sign_in(code : $input);
				} catch(\Throwable $error){
					if($error->getMessage() === 'SESSION_PASSWORD_NEEDED'):
						Logging::echo('Your account has a password !');
					elseif($error->getMessage() === 'PHONE_CODE_INVALID'):
						Logging::echo('The phone code is invalid !');
					endif;
				}
			endif;
			if($this->load->step === Authentication::NEEDPASSWORD):
				$input = Tools::readLine('Please enter your account password : ');
				$this->sign_in(password : $input);
			endif;
			if($this->load->step === Authentication::LOGIN):
				Logging::echo('Your bot is now running...');
			endif;
		else:
			include(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'login.php');
		endif;
		$this->runInBackground();
		if(is_callable($lock)):
			$this->disconnect();
			Logging::log('Client','The start request has been queued for execution !',E_NOTICE);
			$lock();
			Logging::log('Client','Executing a request that was in the queue...',E_NOTICE);
			$this->connect();
		endif;
		$this->registerFilteredFunctions();
		if($this->settings->takeout):
			$this->takeoutid = $this->account->initTakeoutSession(...$this->settings->takeout)->id;
		endif;
		$this->handler->state();
		gc_collect_cycles();
		EventLoop::run();
	}
	public function stop() : void {
		if($this->takeoutid):
			$this->account->finishTakeoutSession();
		endif;
		if($this->connected):
			$this->disconnect();
		endif;
		if($this->locker):
			$this->locker->unlock();
		endif;
	}
	private function runInBackground() : void {
		if(Tools::isCli() === false and headers_sent() === false):
			http_response_code(200);
			if(is_callable('litespeed_finish_request')):
				litespeed_finish_request();
			elseif(is_callable('fastcgi_finish_request')):
				fastcgi_finish_request();
			else:
				ignore_user_abort(true);
				header('Connection: close');
				header('Content-Type: text/html');
				@ob_end_flush();
				@flush();
			endif;
		endif;
	}
	public function disconnect() : void {
		if($this->connected):
			Logging::log('Client','Disconnect !',E_WARNING);
			if(isset($this->sender,$this->transport)):
				$this->sender->close();
				$this->transport->close();
			endif;
			$this->connected = false;
		else:
			Logging::log('Client','You are not connected yet to disconnect !',E_ERROR);
		endif;
	}
	public function __debugInfo() : array {
		return array(
			'config'=>isset($this->config) ? $this->config : new \stdClass,
			'dcoptions'=>$this->dcoptions,
			'devicemodel'=>$this->settings->devicemodel,
			'systemversion'=>$this->settings->systemversion,
			'appversion'=>$this->settings->appversion,
			'systemlangcode'=>$this->settings->systemlangcode,
			'langpack'=>$this->settings->langpack,
			'langcode'=>$this->settings->langcode,
			'hotreload'=>$this->settings->hotreload,
			'floodsleepthreshold'=>$this->settings->floodsleepthreshold,
			'receiveupdates'=>$this->settings->receiveupdates,
			'iptype'=>$this->settings->ipv6 ? 'ipv6' : 'ipv4',
			'takeout'=>$this->settings->takeout,
			'params'=>$this->settings->params,
			'connected'=>$this->connected
		);
	}
	private function __clone() : void {
		$this->session = clone $this->session;
		$this->load = $this->session->load();
		$this->load->sequence = 0;
		$this->load->last_msg_id = 0;
		$dc = end($this->dcoptions);
		$expires_in = intval($dc->expires_at - time());
		$expires_in = intval($expires_in > 0 ? $expires_in : 0);
		$authentication = boolval(($this->load->dc !== $dc->id) || boolval($expires_in));
		$this->setDC($dc->ip_address,$dc->port,$dc->id);
		$this->transport = new TcpTransport($this->load->ip,$this->load->port,$this->load->dc,$this->settings->protocol,$this->settings->proxy);
		if($authentication):
			$connect = new Connect($this->transport,$this->session);
			list($this->load->auth_key,$this->load->time_offset,$this->load->salt) = $connect->authentication($dc->id,$this->session->testmode,$dc->media_only,$expires_in);
		endif;
		$this->sender = new Sender($this->transport,$this->session,$this->handler);
	}
	public function __destruct(){
		$this->disconnect();
	}
	public function __toString() : string {
		return $this->session->getStringSession();
	}
}

?>
