<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Ipc;

use Tak\Liveproto\Utils\Logging;

final class SessionLocker {
	private mixed $fp;

	public function __construct(string $name){
		$this->fp = fopen(sys_get_temp_dir().DIRECTORY_SEPARATOR.urlencode($name).chr(95).md5($_SERVER['SCRIPT_FILENAME']),'c+');
		if($this->fp === false):
			Logging::log('Session Locker','Session not locked',E_ERROR);
			exit('No access to lock the session');
		endif;
	}
	private function commitState(bool $status) : void {
		rewind($this->fp);
		ftruncate($this->fp,0);
		fwrite($this->fp,strval(intval($status)));
		fflush($this->fp);
	}
	public function tryLock() : ? callable {
		Logging::log('Session Locker','Attempt to lock...',0);
		if(flock($this->fp,LOCK_EX | LOCK_NB)):
			$this->commitState(false);
			return null;
		else:
			rewind($this->fp);
			$queue = boolval(fread($this->fp,1));
			$this->commitState(true);
			if($queue === false):
				return function() : void {
					flock($this->fp,LOCK_EX);
					$this->commitState(false);
				};
			else:
				Logging::log('Client','Request blocked !',E_NOTICE);
				fclose($this->fp);
				exit('⚠️ Previous process + one more process are in the execution queue...');
			endif;
		endif;
	}
	public function unlock() : void {
		if(flock($this->fp,LOCK_UN | LOCK_NB)):
			$this->commitState(false);
		endif;
		fclose($this->fp);
	}
}

?>