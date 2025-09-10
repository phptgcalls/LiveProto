<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Utils;

use Revolt\EventLoop;

use function Amp\File\openFile;

use function Amp\File\getSize;

use function Amp\File\deleteFile;

use function Amp\File\exists;

final class Logging {
	private const COLOR_FG = ['black'=>30,'red'=>31,'green'=>32,'yellow'=>33,'blue'=>34,'magenta'=>35,'cyan'=>36,'white'=>37,'reset'=>39];
	private const COLOR_BG = ['black'=>40,'red'=>41,'green'=>42,'yellow'=>43,'blue'=>44,'magenta'=>45,'cyan'=>46,'white'=>47,'reset'=>49];
	private const COLORS = [E_ERROR=>['message'=>'Error','foreground'=>'yellow','background'=>'blue'],E_WARNING=>['message'=>'Warning','foreground'=>'white','background'=>'black'],E_PARSE=>['message'=>'Parse','foreground'=>'red','background'=>'green'],E_NOTICE=>['message'=>'Notice','foreground'=>'magenta','background'=>'cyan']];

	static public string $path = '.'.DIRECTORY_SEPARATOR.'Liveproto.log';
	static public int $maxsize = 0xa00000;
	static public bool $hide = false;

	public function __construct(Settings $settings){
		if(is_string($settings->pathLog)) self::$path = $settings->pathLog;
		if(is_int($settings->maxSizeLog)) self::$maxsize = $settings->maxSizeLog;
		if(is_bool($settings->hideLog)) self::$hide = $settings->hideLog;
	}
	static public function echo(string ...$args) : void {
		if(isset($args['fore']) and array_key_exists($args['fore'],self::COLOR_FG)):
			$FG = $args['fore'];
			unset($args['fore']);
		else:
			$FG = 'reset';
		endif;
		if(isset($args['back']) and array_key_exists($args['back'],self::COLOR_BG)):
			$BG = $args['back'];
			unset($args['back']);
		else:
			$BG = 'reset';
		endif;
		if(Tools::isCli()):
			echo chr(27) , '['.self::COLOR_FG[$FG].';'.self::COLOR_BG[$BG].'m' , implode(chr(0x20),$args) , chr(27) , '[0m' , PHP_EOL;
		else:
			echo '<p style = "color: '.$FG.'; background-color: '.$BG.';">'.implode(chr(0x20),$args).'</p>';
		endif;
	}
	static public function log(string $name,mixed $text,int $level) : void {
		static $log = null;
		if(Tools::inDestructor()) return;
		if(exists(self::$path) and getSize(self::$path) >= self::$maxsize):
			try {
				deleteFile(self::$path);
			} catch(\Throwable $error){
				error_log('Error while deleting previous log : '.strval($error));
			} finally {
				$log = null;
			}
		endif;
		if(exists(self::$path) === false or is_null($log)) $log = openFile(self::$path,'a+');
		if(array_key_exists($level,self::COLORS)):
			$message = self::COLORS[$level]['message'];
			$fg = self::COLORS[$level]['foreground'];
			$bg = self::COLORS[$level]['background'];
		else:
			$message = 'Info';
			$fg = 'cyan';
			$bg = 'black';
		endif;
		$backtrace = debug_backtrace();
		$log->write(str_pad($name.' ( '.$message.' ) ',0x20,chr(0x20),STR_PAD_RIGHT).'[ '.date('Y/m/d H:i:s').' ]'.' : '.print_r($text,true).' on line '.$backtrace[false]['line'].PHP_EOL);
		if(self::$hide === false) self::echo(str_pad($message,10,chr(0x20),STR_PAD_RIGHT).' : '.print_r($text,true),fore : $fg, back : $bg);
	}
}

?>