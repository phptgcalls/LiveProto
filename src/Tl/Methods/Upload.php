<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Utils\Helper;

use Tak\Liveproto\Utils\Logging;

use Tak\Liveproto\Utils\Binary;

use Tak\Liveproto\Crypto\Aes;

use function Amp\async;

use function Amp\File\openFile;

use function Amp\File\getSize;

use function Amp\File\isFile;

trait Upload {
	public function upload_file(string $path,? callable $progresscallback = null,? string $key = null,? string $iv = null) : mixed {
		if(isFile($path)):
			$stream = openFile($path,'rb');
			$size = getSize($path);
			$partSizeKB = ($size <= 0x6400000 ? 128 : ($size <= 0x2ee00000 ? 256 : 512));
			$partSize = intval($partSizeKB * 1024);
			$partCount = intdiv($size + $partSize - 1,$partSize);
			$fileid = Helper::generateRandomLong();
			$isBig = ($size > 0xa00000);
			$progress = 0;
			$md5 = hash_init('md5');
			Logging::log('Upload','Start uploading the '.basename($path).' file ...',0);
			for($partIndex = 0;$partIndex < $partCount;$partIndex++):
				$part = $stream->read(length : $partSize);
				if(strlen($part) !== $partSize and $partIndex < $partCount - 1):
					throw new \Exception('Read method isn\'t correct !');
				endif;
				if(is_null($key) === false and is_null($iv) === false):
					$part = Aes::encrypt($part,$key,$iv);
				endif;
				if($isBig):
					$isSaved = $this->upload->saveBigFilePart(file_id : $fileid,file_part : $partIndex,file_total_parts : $partCount,bytes : $part);
				else:
					hash_update($md5,$part);
					$isSaved = $this->upload->saveFilePart(file_id : $fileid,file_part : $partIndex,bytes : $part);
				endif;
				$progress += strlen($part);
				$percent = ($progress / $size) * 100;
				if(isset($isSaved->bool) and $isSaved->bool):
					if(is_null($progresscallback) === false):
						if(async($progresscallback(...),$percent)->await() === false):
							break;
						endif;
					else:
						Logging::log('Upload',$percent.'%',0);
					endif;
				else:
					throw new \Exception('Failed to upload file parts !');
				endif;
			endfor;
			$stream->close();
			Logging::log('Upload','Finish uploading the '.basename($path).' file ...',0);
			if(is_null($key) === false and is_null($iv) === false):
				$hash = new Binary();
				$hash->write(md5($key.$iv,true));
				$fingerprint = $hash->readInt() ^ $hash->readInt();
				return $isBig ? $this->inputEncryptedFileBigUploaded(id : $fileid,parts : $partCount,key_fingerprint : $fingerprint) : $this->inputEncryptedFileUploaded(id : $fileid,parts : $partCount,md5_checksum : hash_final($md5,true),key_fingerprint : $fingerprint);
			else:
				return $isBig ? $this->inputFileBig(id : $fileid,parts : $partCount,name : $path) : $this->inputFile(id : $fileid,parts : $partCount,name : $path,md5_checksum : hash_final($md5,true));
			endif;
		else:
			throw new \Exception('file '.$path.' not found !');
		endif;
	}
	public function upload_secret_file(string $path,mixed ...$arguments) : array {
		$arguments += ['key'=>random_bytes(32),'iv'=>random_bytes(32)];
		return array($this->upload_file($path,...$arguments),$arguments['key'],$arguments['iv']);
	}
}

?>