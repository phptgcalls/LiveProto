<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Crypto\Aes;

use Tak\Liveproto\Utils\Security;

use Tak\Liveproto\Utils\Binary;

use Tak\Liveproto\Utils\Errors;

use Tak\Liveproto\Utils\Logging;

use function Amp\async;

use function Amp\File\openFile;

use function Amp\File\isDirectory;

use function Amp\File\move;

trait Download {
	public function download_file(string $path,int $size,int $dcid,object $location,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
		$stream = openFile($path,'wb');
		$percent = 0;
		$offset = 0;
		$limit = $this->getChuckSize($size);
		$client = $this->switchDC(dcid : $dcid,media : true);
		try {
			$getFile = $client->upload->getFile(location : $location,offset : $offset,limit : $limit,cdn_supported : true,timeout : 10);
		} catch(Errors $error){
			if($error->getCode() == 303):
				$dcid = $error->getValue();
				return $this->download_file($path,$size,$dcid,$location,$progresscallback,$key,$iv);
			else:
				throw $error;
			endif;
		}
		Logging::log('Download','Start downloading the '.basename($path).' file ...',0);
		if($getFile instanceof \Tak\Liveproto\Tl\Types\Upload\FileCdnRedirect):
			$client = $this->switchDC(dcid : $getFile->dc_id,cdn : true,media : true);
			while($size > $offset or $size <= 0):
				$getCdnFile = $client->upload->getCdnFile(file_token : $getFile->file_token,offset : $offset,limit : $limit,timeout : 10);
				if($getCdnFile instanceof \Tak\Liveproto\Tl\Types\Upload\CdnFileReuploadNeeded):
					try {
						$client->upload->reuploadCdnFile(file_token : $getFile->file_token,request_token : $getCdnFile->request_token);
						continue;
					} catch(\Throwable $error){
						break;
					}
				endif;
				$key = $getFile->encryption_key;
				$iv = substr($getFile->encryption_iv,0,-4).pack('N',$offset >> 4);
				$bytes = openssl_decrypt($getCdnFile->bytes,'AES-256-CTR',$key,OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,$iv);
				$hashes = $client->upload->getCdnFileHashes(file_token : $getFile->file_token,offset : $offset);
				foreach($hashes as $i => $value):
					$hash = substr($bytes,$value->limit * $i,$value->limit);
					assert($value->hash === hash('sha256',$hash,true),new Security('File validation failed !'));
				endforeach;
				$offset += strlen($getCdnFile->bytes);
				$stream->write($bytes);
				if($size > 0):
					$percent = min(100,($offset / $size) * 100);
					if(is_null($progresscallback) === false):
						if(async($progresscallback(...),$percent)->await() === false):
							Logging::log('Download','Canceled !',E_WARNING);
							throw new \RuntimeException('Download canceled !');
						endif;
					else:
						Logging::log('Download Cdn',$percent.'%',0);
					endif;
				endif;
				if($limit > strlen($getCdnFile->bytes)) break;
			endwhile;
		elseif($getFile instanceof \Tak\Liveproto\Tl\Types\Upload\File):
			while($size > $offset or $size <= 0):
				$getFile = $client->upload->getFile(location : $location,offset : $offset,limit : $limit,timeout : 10);
				$offset += strlen($getFile->bytes);
				if(is_null($key) === false and is_null($iv) === false):
					$getFile->bytes = Aes::decrypt($getFile->bytes,$key,$iv);
				endif;
				$stream->write($getFile->bytes);
				if($size > 0):
					$percent = min(100,($offset / $size) * 100);
					if(is_null($progresscallback) === false):
						if(async($progresscallback(...),$percent)->await() === false):
							Logging::log('Download','Canceled !',E_WARNING);
							throw new \RuntimeException('Download canceled !');
						endif;
					else:
						Logging::log('Download',$percent.'%',0);
					endif;
				endif;
				if($limit > strlen($getFile->bytes)) break;
			endwhile;
		endif;
		if(is_null($progresscallback) === false and $percent != 100):
			if(async($progresscallback(...),floatval(100))->await() === false):
				Logging::log('Download','Canceled !',E_WARNING);
				throw new \RuntimeException('Download canceled !');
			endif;
		endif;
		$stream->close();
		try {
			if(empty(pathinfo($path,PATHINFO_EXTENSION))):
				$extension = $this->getFileExtension($getFile->type);
				$extension = (empty($extension) ? $this->getFileExtension(mime_content_type($path)) : $extension);
				$newpath = $path.chr(46).$extension;
				move($path,$newpath);
			endif;
		} catch(\Throwable $error){
			Logging::log('Download','I could not change the '.basename($path).' file extension ...',0);
		}
		Logging::log('Download','Finish downloading the '.basename($path).' file ...',0);
		return isset($newpath) ? $newpath : $path;
	}
	public function download_photo(string $path,object $file,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\MessageMediaPhoto):
			$file = $file->photo ? $file->photo : throw new \InvalidArgumentException('The message does not contain the photo property !');
		endif;
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\Photo):
			$dcid = $file->dc_id;
			$photoSize = end($file->sizes);
			if($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoStrippedSize or $photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoCachedSize):
				return $this->photoCachedSize($path,$photoSize);
			endif;
			list($type,$size) = $this->getPhotoSize($photoSize);
			$location = $this->inputPhotoFileLocation(id : $file->id,access_hash : $file->access_hash,file_reference : $file->file_reference,thumb_size : $type);
			if(isDirectory($path)):
				$path = $path.DIRECTORY_SEPARATOR.strval($file->id);
			endif;
		else:
			throw new \InvalidArgumentException('Your media does not contain photo !');
		endif;
		return $this->download_file($path,$size,$dcid,$location,$progresscallback,$key,$iv);
	}
	public function download_profile_photo(string $path,object $file,bool $big = true,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\User or $file instanceof \Tak\Liveproto\Tl\Types\Other\Chat or $file instanceof \Tak\Liveproto\Tl\Types\Other\Channel):
			$size = PHP_INT_MAX;
			$peer = $this->get_input_peer($file->id);
			$photo = $file->photo ? $file->photo : throw new \InvalidArgumentException('The user does not contain the photo property !');
			$dcid = $photo->dc_id;
			$location = $this->inputPeerPhotoFileLocation(peer : $peer,photo_id : $photo->photo_id,big : ($big ? true : null));
			if(isDirectory($path)):
				$path = $path.DIRECTORY_SEPARATOR.strval($photo->photo_id);
			endif;
			return $this->download_file($path,$size,$dcid,$location,$progresscallback,$key,$iv);
		elseif($file instanceof \Tak\Liveproto\Tl\Types\Other\UserFull):
			$photo = $file->profile_photo ? $file->profile_photo : throw new \InvalidArgumentException('The user does not contain the profile photo property !');
			if($big) $photo = $this->photoCachedIgnore($photo);
			return $this->download_photo($path,$photo,$progresscallback,$key,$iv);
		elseif($file instanceof \Tak\Liveproto\Tl\Types\Other\ChatFull or $file instanceof \Tak\Liveproto\Tl\Types\Other\ChannelFull):
			$photo = $file->chat_photo ? $file->chat_photo : throw new \InvalidArgumentException('The user does not contain the chat photo property !');
			if($big) $photo = $this->photoCachedIgnore($photo);
			return $this->download_photo($path,$photo,$progresscallback,$key,$iv);
		else:
			return $this->download_photo($path,$file,$progresscallback,$key,$iv);
		endif;
	}
	public function download_document(string $path,object $file,? callable $progresscallback = null,? string $key = null,? string $iv = null,bool $thumb = false) : string {
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\MessageMediaDocument):
			$file = $file->document ? $file->document : throw new \InvalidArgumentException('The message does not contain the document property !');
		endif;
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\Document):
			$dcid = $file->dc_id;
			$size = $file->size;
			if($file->thumbs === null or $thumb === false):
				$type = strval(null);
			else:
				$file->mime_type = 'image/png';
				$thumb = end($file->thumbs);
				if($thumb instanceof \Tak\Liveproto\Tl\Types\Other\PhotoStrippedSize or $thumb instanceof \Tak\Liveproto\Tl\Types\Other\PhotoCachedSize):
					return $this->photoCachedSize($path,$thumb);
				endif;
				list($type,$size) = $this->getPhotoSize($thumb);
			endif;
			$location = $this->inputDocumentFileLocation(id : $file->id,access_hash : $file->access_hash,file_reference : $file->file_reference,thumb_size : $type);
			if(isDirectory($path)):
				$path = $path.DIRECTORY_SEPARATOR.strval($file->id);
			endif;
			if(empty(pathinfo($path,PATHINFO_EXTENSION))):
				$extension = $this->getFileExtension($file->mime_type);
				if(empty($extension) === false):
					$path = $path.chr(46).$extension;
				endif;
			endif;
		else:
			throw new \InvalidArgumentException('Your media does not contain document !');
		endif;
		return $this->download_file($path,$size,$dcid,$location,$progresscallback,$key,$iv);
	}
	public function download_web_document(string $path,object $file) : string {
		if(isset($file->photo)):
			$file = $file->photo ? $file->photo : $file;
		endif;
		if(isset($file->content)):
			$file = $file->content ? $file->content : $file;
		endif;
		if(isset($file->thumb)):
			$file = $file->thumb ? $file->thumb : $file;
		endif;
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\WebDocument or $file instanceof \Tak\Liveproto\Tl\Types\Other\WebDocumentNoProxy or $file instanceof \Tak\Liveproto\Tl\Types\Secret\DecryptedMessageMediaWebPage or $file instanceof \Tak\Liveproto\Tl\Types\Other\InputWebDocument):
			$url = isset($file->url) ? $file->url : throw new \InvalidArgumentException('The web document does not contain the url property !');
			$mimeType = isset($file->mime_type) ? $file->mime_type : null;
			$headers = get_headers($url,true);
			if($headers !== false):
				$headers = array_change_key_case($headers,CASE_LOWER);
				if(isset($headers['content-type'])):
					$mimeType = $headers['content-type'];
				endif;
			endif;
			if(isDirectory($path)):
				$path = $path.DIRECTORY_SEPARATOR.md5($url);
			endif;
			if(empty(pathinfo($path,PATHINFO_EXTENSION)) and empty($mimeType) === false):
				$extension = $this->getFileExtension($mimeType);
				if(empty($extension) === false):
					$path = $path.chr(46).$extension;
				endif;
			endif;
			$buffer = @file_get_contents($url);
			if(is_string($buffer)):
				$stream = openFile($path,'wb');
				$stream->write($buffer);
				$stream->close();
				return $path;
			else:
				throw new \RuntimeException('Error retrieving buffer : '.$url);
			endif;
		else:
			throw new \InvalidArgumentException('Your media does not contain web document !');
		endif;
	}
	public function download_contact(string $path,object $file) : string {
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\MessageMediaContact or $file instanceof \Tak\Liveproto\Tl\Types\Other\InputMediaContact or $file instanceof \Tak\Liveproto\Tl\Types\Other\BotInlineMessageMediaContact or $file instanceof \Tak\Liveproto\Tl\Types\Other\InputBotInlineMessageMediaContact):
			$vcard = isset($file->vcard) ? $file->vcard : throw new \InvalidArgumentException('The contact does not contain the vcard property !');
			if(isDirectory($path)):
				$path = $path.DIRECTORY_SEPARATOR.strval($file->user_id);
			endif;
			if(empty(pathinfo($path,PATHINFO_EXTENSION))):
				$path = $path.chr(46).'vcard';
			endif;
			$stream = openFile($path,'wb');
			$stream->write($vcard);
			$stream->close();
			return $path;
		else:
			throw new \InvalidArgumentException('Your object is not message media contact !');
		endif;
	}
	public function download_secret_file(string $path,object $file,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\UpdateNewEncryptedMessage):
			$file = $file->decrypted;
		endif;
		if($file instanceof \Tak\Liveproto\Tl\Types\Secret\DecryptedMessage):
			if(is_object($file->media)):
				$key = $file->media->key;
				$iv = $file->media->iv;
				$file = $file->file;
			endif;
		endif;
		if($file instanceof \Tak\Liveproto\Tl\Types\Other\EncryptedFile):
			if(is_null($key) === false and is_null($iv) === false):
				$hash = new Binary();
				$hash->write(md5($key.$iv,true));
				$fingerprint = $hash->readInt() ^ $hash->readInt();
				if($fingerprint !== $file->key_fingerprint):
					throw new \LogicException('Invalid key fingerprint !');
				endif;
				$size = $file->size;
				$dcid = $file->dc_id;
				$location = $this->inputEncryptedFileLocation(id : $file->id,access_hash : $file->access_hash);
				return $this->download_file($path,$size,$dcid,$location,$progresscallback,$key,$iv);
			else:
				throw new \InvalidArgumentException('The value of key and iv arguments should not be null !');
			endif;
		else:
			throw new \InvalidArgumentException('File object is not instance of EncryptedFile !');
		endif;
	}
	public function download_media(string $path,object $file,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
		try {
			if($file instanceof \Tak\Liveproto\Tl\Types\Other\MessageMediaContact or $file instanceof \Tak\Liveproto\Tl\Types\Other\InputMediaContact or $file instanceof \Tak\Liveproto\Tl\Types\Other\BotInlineMessageMediaContact or $file instanceof \Tak\Liveproto\Tl\Types\Other\InputBotInlineMessageMediaContact):
				return $this->download_contact($path,$file);
			elseif($file instanceof \Tak\Liveproto\Tl\Types\Other\WebDocument or $file instanceof \Tak\Liveproto\Tl\Types\Other\WebDocumentNoProxy):
				return $this->download_web_document($path,$file);
			elseif($file instanceof \Tak\Liveproto\Tl\Types\Other\MessageMediaDocument or $file instanceof \Tak\Liveproto\Tl\Types\Other\Document):
				return $this->download_document($path,$file,$progresscallback,$key,$iv);
			elseif($file instanceof \Tak\Liveproto\Tl\Types\Other\MessageMediaPhoto or $file instanceof \Tak\Liveproto\Tl\Types\Other\Photo):
				return $this->download_photo($path,$file,$progresscallback,$key,$iv);
			elseif($file instanceof \Tak\Liveproto\Tl\Types\Other\UpdateNewEncryptedMessage or $file instanceof \Tak\Liveproto\Tl\Types\Secret\DecryptedMessage or $file instanceof \Tak\Liveproto\Tl\Types\Other\EncryptedFile):
				return $this->download_secret_file($path,$file,$progresscallback,$key,$iv);
			else:
				return $this->download_profile_photo($path,$file,$progresscallback,$key,$iv);
			endif;
		} catch(\Throwable $e){
			error_log($e->getMessage());
			throw new \InvalidArgumentException('Invalid input media !');
		}
	}
	private function getPhotoSize(object $photoSize) : array {
		if($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoSizeEmpty):
			return array($photoSize->type,0);
		elseif($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoSize):
			return array($photoSize->type,$photoSize->size);
		elseif($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoCachedSize):
			return array($photoSize->type,strlen($photoSize->bytes));
		elseif($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoSizeProgressive):
			return array($photoSize->type,max($photoSize->sizes));
		elseif($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoPathSize):
			return array($photoSize->type,strlen($photoSize->bytes));
		elseif($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoStrippedSize):
			if(strlen($photoSize->bytes) < 3 or substr($photoSize->bytes,0,1) != 1):
				return array($photoSize->type,strlen($photoSize->bytes));
			else:
				return array($photoSize->type,strlen($photoSize->bytes) + 0x26e);
			endif;
		else:
			throw new \InvalidArgumentException('Unknown photoSize !');
		endif;
	}
	public function photoCachedSize(string $path,object $photoSize) : string {
		if($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoStrippedSize):
			$bytes = $this->getPhotoStrippedJpg($photoSize->bytes);
		elseif($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoCachedSize):
			$bytes = $photoSize->bytes;
		else:
			throw new \InvalidArgumentException('Invalid photoSize !');
		endif;
		if(isDirectory($path)):
			$path = $path.DIRECTORY_SEPARATOR.md5($bytes);
		endif;
		if(empty(pathinfo($path,PATHINFO_EXTENSION))):
			$path = $path.chr(46).'jpg';
		endif;
		$stream = openFile($path,'wb');
		$stream->write($bytes);
		$stream->close();
		return $path;
	}
	public function getPhotoStrippedJpg(string $stripped) : string {
		if(strlen($stripped) < 3 or substr($stripped,0,1) !== chr(1)):
			return $stripped;
		else:
			$header = "\xff\xd8\xff\xe0\x00\x10\x4a\x46\x49\x46\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xdb\x00\x43\x00\x28\x1c\x1e\x23\x1e\x19\x28\x23\x21\x23\x2d\x2b\x28\x30\x3c\x64\x41\x3c\x37\x37\x3c\x7b\x58\x5d\x49\x64\x91\x80\x99\x96\x8f\x80\x8c\x8a\xa0\xb4\xe6\xc3\xa0\xaa\xda\xad\x8a\x8c\xc8\xff\xcb\xda\xee\xf5\xff\xff\xff\x9b\xc1\xff\xff\xff\xfa\xff\xe6\xfd\xff\xf8\xff\xdb\x00\x43\x01\x2b\x2d\x2d\x3c\x35\x3c\x76\x41\x41\x76\xf8\xa5\x8c\xa5\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xf8\xff\xc0\x00\x11\x08\x00\x00\x00\x00\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xff\xc4\x00\x1f\x00\x00\x01\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\xff\xc4\x00\xb5\x10\x00\x02\x01\x03\x03\x02\x04\x03\x05\x05\x04\x04\x00\x00\x01\x7d\x01\x02\x03\x00\x04\x11\x05\x12\x21\x31\x41\x06\x13\x51\x61\x07\x22\x71\x14\x32\x81\x91\xa1\x08\x23\x42\xb1\xc1\x15\x52\xd1\xf0\x24\x33\x62\x72\x82\x09\x0a\x16\x17\x18\x19\x1a\x25\x26\x27\x28\x29\x2a\x34\x35\x36\x37\x38\x39\x3a\x43\x44\x45\x46\x47\x48\x49\x4a\x53\x54\x55\x56\x57\x58\x59\x5a\x63\x64\x65\x66\x67\x68\x69\x6a\x73\x74\x75\x76\x77\x78\x79\x7a\x83\x84\x85\x86\x87\x88\x89\x8a\x92\x93\x94\x95\x96\x97\x98\x99\x9a\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xff\xc4\x00\x1f\x01\x00\x03\x01\x01\x01\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\xff\xc4\x00\xb5\x11\x00\x02\x01\x02\x04\x04\x03\x04\x07\x05\x04\x04\x00\x01\x02\x77\x00\x01\x02\x03\x11\x04\x05\x21\x31\x06\x12\x41\x51\x07\x61\x71\x13\x22\x32\x81\x08\x14\x42\x91\xa1\xb1\xc1\x09\x23\x33\x52\xf0\x15\x62\x72\xd1\x0a\x16\x24\x34\xe1\x25\xf1\x17\x18\x19\x1a\x26\x27\x28\x29\x2a\x35\x36\x37\x38\x39\x3a\x43\x44\x45\x46\x47\x48\x49\x4a\x53\x54\x55\x56\x57\x58\x59\x5a\x63\x64\x65\x66\x67\x68\x69\x6a\x73\x74\x75\x76\x77\x78\x79\x7a\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x92\x93\x94\x95\x96\x97\x98\x99\x9a\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xff\xda\x00\x0c\x03\x01\x00\x02\x11\x03\x11\x00\x3f\x00";
			$footer = "\xff\xd9";
			$header[164] = $stripped[1];
			$header[166] = $stripped[2];
			return $header.substr($stripped,3).$footer;
		endif;
	}
	public function photoCachedIgnore(object $photo) : object {
		while(isset($photo->sizes) and is_array($photo->sizes) and count($photo->sizes) > 1):
			$photoSize = end($photo->sizes);
			if($photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoStrippedSize or $photoSize instanceof \Tak\Liveproto\Tl\Types\Other\PhotoCachedSize):
				array_pop($photo->sizes);
			else:
				break;
			endif;
		endwhile;
		return $photo;
	}
	private function getChuckSize(int $size) : int {
		$n = ceil(log(intdiv($size,0x1000) + 0x1,0x2));
		return intval($n > 0x8 ? pow(0x400,0x2) : 0x1000 * pow(0x2,$n));
	}
	private function getFileExtension(object | string $type) : string {
		if(is_object($type)):
			return match(true){
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FileJpeg => 'jpeg',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FileGif => 'gif',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FilePng => 'png',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FilePdf => 'pdf',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FileMp3 => 'mp3',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FileMov => 'mov',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FileMp4 => 'mp4',
				$type instanceof \Tak\Liveproto\Tl\Types\Storage\FileWebp => 'webp',
				default => strval(null)
			};
		else:
			return match(strtolower($type)){
				'text/h323' => '323',
				'application/internet-property-stream' => 'acx',
				'application/postscript' => 'ps',
				'audio/x-aiff' => 'aiff',
				'video/x-ms-asf' => 'asx',
				'audio/basic' => 'snd',
				'video/x-msvideo' => 'avi',
				'application/olescript' => 'axs',
				'text/plain' => 'txt',
				'application/x-bcpio' => 'bcpio',
				'image/bmp' => 'bmp',
				'application/vnd.ms-pkiseccat' => 'cat',
				'application/x-netcdf' => 'nc',
				'application/x-x509-ca-cert' => 'der',
				'application/x-msclip' => 'clp',
				'image/x-cmx' => 'cmx',
				'image/cis-cod' => 'cod',
				'application/x-cpio' => 'cpio',
				'application/x-mscardfile' => 'crd',
				'application/pkix-crl' => 'crl',
				'application/x-csh' => 'csh',
				'text/css' => 'css',
				'application/x-director' => 'dir',
				'application/x-msdownload' => 'dll',
				'application/msword' => 'dot',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
				'application/x-dvi' => 'dvi',
				'text/x-setext' => 'etx',
				'application/envoy' => 'evy',
				'application/fractals' => 'fif',
				'model/vrml' => 'vrml',
				'image/gif' => 'gif',
				'application/x-gtar' => 'gtar',
				'application/gzip' , 'application/x-gzip' => 'gz',
				'application/x-hdf' => 'hdf',
				'application/winhlp' => 'hlp',
				'application/mac-binhex40' => 'hqx',
				'application/hta' => 'hta',
				'text/x-component' => 'htc',
				'text/html' => 'html',
				'text/webviewhtml' => 'htt',
				'image/x-icon' => 'ico',
				'image/ief' => 'ief',
				'application/x-iphone' => 'iii',
				'application/x-internet-signup' => 'isp',
				'image/pipeg' => 'jfif',
				'image/jpeg' => 'jpeg',
				'image/png' => 'png',
				'application/x-javascript' => 'js',
				'application/x-latex' => 'latex',
				'video/x-la-asf' => 'lsx',
				'application/x-msmediaview' => 'mvb',
				'audio/x-mpegurl' => 'm3u',
				'application/x-troff-man' => 'man',
				'application/x-msaccess' => 'mdb',
				'application/x-troff-me' => 'me',
				'message/rfc822' => 'nws',
				'audio/mid' => 'rmi',
				'application/x-msmoney' => 'mny',
				'video/quicktime' => 'mov',
				'video/x-sgi-movie' => 'movie',
				'video/mpeg' => 'mpv2',
				'audio/mpeg' => 'mp3',
				'audio/ogg' => 'ogg',
				'application/vnd.ms-project' => 'mpp',
				'application/x-troff-ms' => 'ms',
				'application/vnd.ms-outlook' => 'msg',
				'application/oda' => 'oda',
				'application/pkcs10' => 'p10',
				'application/x-pkcs12' => 'pfx',
				'application/x-pkcs7-certificates' => 'spc',
				'application/x-pkcs7-mime' => 'p7m',
				'application/x-pkcs7-certreqresp' => 'p7r',
				'application/x-pkcs7-signature' => 'p7s',
				'image/x-portable-bitmap' => 'pbm',
				'application/pdf' => 'pdf',
				'image/x-portable-graymap' => 'pgm',
				'application/ynd.ms-pkipko' => 'pko',
				'application/x-perfmon' => 'pmw',
				'image/x-portable-anymap' => 'pnm',
				'application/vnd.ms-powerpoint' => 'ppt',
				'image/x-portable-pixmap' => 'ppm',
				'application/pics-rules' => 'prf',
				'application/x-mspublisher' => 'pub',
				'audio/x-pn-realaudio' => 'ram',
				'image/x-cmu-raster' => 'ras',
				'image/x-rgb' => 'rgb',
				'application/x-troff' => 'tr',
				'application/rtf' => 'rtf',
				'text/richtext' => 'rtx',
				'application/x-msschedule' => 'scd',
				'text/scriptlet' => 'sct',
				'application/set-payment-initiation' => 'setpay',
				'application/set-registration-initiation' => 'setreg',
				'application/x-sh' => 'sh',
				'application/x-shar' => 'shar',
				'application/x-stuffit' => 'sit',
				'application/futuresplash' => 'spl',
				'application/x-wais-source' => 'src',
				'application/vnd.ms-pkicertstore' => 'sst',
				'application/vnd.ms-pkistl' => 'stl',
				'application/x-sv4cpio' => 'sv4cpio',
				'application/x-sv4crc' => 'sv4crc',
				'image/svg+xml' => 'svg',
				'application/x-shockwave-flash' => 'swf',
				'application/x-tar' => 'tar',
				'application/x-tcl' => 'tcl',
				'application/x-tex' => 'tex',
				'application/x-texinfo' => 'texinfo',
				'application/x-compressed' => 'tgz',
				'image/tiff' => 'tiff',
				'application/x-msterminal' => 'trm',
				'text/tab-separated-values' => 'tsv',
				'text/iuls' => 'uls',
				'application/x-ustar' => 'ustar',
				'text/x-vcard' => 'vcf',
				'text/vcard' => 'vcard',
				'audio/x-wav' => 'wav',
				'application/vnd.ms-works' => 'wps',
				'application/x-msmetafile' => 'wmf',
				'application/x-mswrite' => 'wri',
				'wri' => 'application/x-mswrite',
				'image/x-xbitmap' => 'xbm',
				'image/x-xpixmap' => 'xpm',
				'image/x-xwindowdump' => 'xwd',
				'application/x-compress' => 'z',
				'image/webp' => 'webp',
				'application/zip' , 'application/x-zip-compressed' => 'zip',
				'video/mp4' => 'mp4',
				default => strval(null)
			};
		endif;
	}
}

?>