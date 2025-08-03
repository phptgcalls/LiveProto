<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Crypto\Rle;

use Tak\Liveproto\Utils\Binary;

use Tak\Liveproto\Utils\Instance;

use Tak\Liveproto\Enums\FileIdType;

use Tak\Liveproto\Enums\PhotoSizeType;

trait FileId {
	private const WEB_LOCATION_FLAG =  1 << 24;
	private const FILE_REFERENCE_FLAG = 1 << 25;

	public function fromBotAPI(string $file_id) : object {
		$file = Rle::decode(base64_decode(strtr($file_id,chr(45).chr(95),chr(43).chr(47))));
		$version = ord($file[strlen($file) - 1]);
		$subVersion = intval($version === 4 ? ord($file[strlen($file) - 2]) : 0);
		$writer = new Binary();
		$writer->write($file);
		$type = $writer->readInt();
		$dc = $writer->readInt();
		$fileReference = ($type & self::FILE_REFERENCE_FLAG ? $writer->tgreadBytes() : null);
		$hasWebLocation = boolval($type & self::WEB_LOCATION_FLAG);
		$type &= ~ self::FILE_REFERENCE_FLAG;
		$type &= ~ self::WEB_LOCATION_FLAG;
		if($hasWebLocation):
			$url = $writer->tgreadBytes();
			$accessHash = $writer->readLong();
			$headers = get_headers($url,true);
			$size = 0;
			$mimeType = null;
			if($headers !== false):
				$headers = array_change_key_case($headers,CASE_LOWER);
				if(isset($headers['content-length'])):
					$size = $headers['content-length'];
				endif;
				if(isset($headers['content-type'])):
					$mimeType = $headers['content-type'];
				endif;
			endif;
			$input = $this->inputWebDocument(url : $url,size : $size,mime_type : $mimeType,attributes : array($this->documentAttributeFilename(file_name : basename($url))));
			$data = ['version'=>$version,'sub_version'=>$subVersion,'dc_id'=>$dc,'file_reference'=>$fileReference,'url'=>$url,'access_hash'=>$accessHash,'input_location'=>$input];
			$anonymous = new class($data) extends Instance {
				public function download(string $path) : string {
					return $this->download_web_document($path,$this->input_location);
				}
			};
			return $anonymous->setClient($this);
		else:
			$id = $writer->readLong();
			$accessHash = $writer->readLong();
			$input = $this->inputDocumentFileLocation(id : $id,access_hash : $accessHash,file_reference : $fileReference,thumb_size : strval(null));
		endif;
		$volume_id = null;
		$local_id = null;
		$photoSizeSource = null;
		if($type <= FileIdType::PHOTO->toId()):
			if($subVersion < 32):
				$volume_id = $writer->readLong();
				$local_id = $writer->readInt();
			endif;
			$arg = ($subVersion >= 4 ? $writer->readInt() : 0);
			$photosize = PhotoSizeType::from($arg);
			switch($photosize):
				case PhotoSizeType::LEGACY:
					$secret = $writer->readLong();
					$input = $this->inputPhotoLegacyFileLocation(id : $id,access_hash : $accessHash,file_reference : $fileReference,volume_id : $volume_id,local_id : $local_id,secret : $secret);
					break;
				case PhotoSizeType::THUMBNAIL:
					$type = $writer->readInt();
					$input->thumb_size = chr($writer->readInt());
					break;
				case PhotoSizeType::DIALOGPHOTO_SMALL:
				case PhotoSizeType::DIALOGPHOTO_BIG:
					$peer = $writer->readLong();
					$hash = $writer->readLong();
					$input = $this->inputPeerPhotoFileLocation(peer : $this->get_input_peer($peer,$hash),photo_id : $id,big : ($photosize === PhotoSizeType::DIALOGPHOTO_BIG ? true : null));
					break;
				case PhotoSizeType::STICKERSET_THUMBNAIL:
					$stickerSetId = $writer->readLong();
					$stickerSetAccessHash = $writer->readLong();
					$input = $this->inputStickerSetThumb(stickerset : $this->inputStickerSetID(id : $stickerSetId,access_hash : $stickerSetAccessHash),thumb_version : 0);
					break;
				case PhotoSizeType::FULL_LEGACY:
					$volume_id = $writer->readLong();
					$secret = $writer->readLong();
					$local_id = $writer->readInt();
					$input = $this->inputPhotoLegacyFileLocation(id : $id,access_hash : $accessHash,file_reference : $fileReference,volume_id : $volume_id,local_id : $local_id,secret : $secret);
					break;
				case PhotoSizeType::DIALOGPHOTO_SMALL_LEGACY:
				case PhotoSizeType::DIALOGPHOTO_BIG_LEGACY:
					$peer = $writer->readLong();
					$hash = $writer->readLong();
					$input = $this->inputPeerPhotoFileLocation(peer : $this->get_input_peer($peer,$hash),photo_id : $id,big : ($photosize === PhotoSizeType::DIALOGPHOTO_BIG_LEGACY ? true : null));
					$volume_id = $writer->readLong();
					$local_id = $writer->readInt();
					break;
				case PhotoSizeType::STICKERSET_THUMBNAIL_LEGACY:
					$stickerSetId = $writer->readLong();
					$stickerSetAccessHash = $writer->readLong();
					$input = $this->inputStickerSetThumb(stickerset : $this->inputStickerSetID(id : $stickerSetId,access_hash : $stickerSetAccessHash),thumb_version : 0);
					$volume_id = $writer->readLong();
					$local_id = $writer->readInt();
					break;
				case PhotoSizeType::STICKERSET_THUMBNAIL_VERSION:
					$stickerSetId = $writer->readLong();
					$stickerSetAccessHash = $writer->readLong();
					$stickerThumbVersion = $writer->readInt();
					$input = $this->inputStickerSetThumb(stickerset : $this->inputStickerSetID(id : $stickerSetId,access_hash : $stickerSetAccessHash),thumb_version : $stickerThumbVersion);
					break;
			endswitch;
		endif;
		$x = strlen($writer->read());
		$x -= $version >= 4 ? 2 : 1;
		if($x > 0) Logging::log('FileId','File ID '.$file_id.' has '.strval($x).' bytes left !',E_WARNING);
		$data = ['version'=>$version,'sub_version'=>$subVersion,'dc_id'=>$dc,'file_reference'=>$fileReference,'file_type'=>FileIdType::fromId($type),'id'=>$id,'access_hash'=>$accessHash,'volume_id'=>$volume_id,'local_id'=>$local_id,'input_location'=>$input];
		$anonymous = new class($data) extends Instance {
			public function download(string $path,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
				return $this->download_file($path,PHP_INT_MAX,$this->dc_id,$this->input_location,$progresscallback,$key,$iv);
			}
		};
		return $anonymous->setClient($this);
	}
}

?>