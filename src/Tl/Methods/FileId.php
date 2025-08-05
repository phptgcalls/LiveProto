<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Tl\Methods;

use Tak\Liveproto\Crypto\Rle;

use Tak\Liveproto\Utils\Tools;

use Tak\Liveproto\Utils\Binary;

use Tak\Liveproto\Utils\Instance;

use Tak\Liveproto\Utils\Logging;

use Tak\Liveproto\Enums\FileIdType;

use Tak\Liveproto\Enums\PhotoSizeType;

# https://github.com/tdlib/td/blob/master/td/telegram/files/FileLocation.hpp #
trait FileId {
	private const WEB_LOCATION_FLAG =  1 << 24;
	private const FILE_REFERENCE_FLAG = 1 << 25;
	public const REMOVE_PHOTO_VOLUME_AND_LOCALID = 32;
	public const ADD_PHOTO_SIZE_SOURCE = 22;

	public function fromBotAPI(string $file_id) : object {
		$file = Rle::decode(Tools::base64_url_decode($file_id));
		$version = ord(substr($file,-1,1));
		$subVersion = intval($version >= 4 ? ord(substr($file,-2,1)) : 0);
		$reader = new Binary();
		$reader->write($file);
		$type = $reader->readInt();
		$dc = $reader->readInt();
		$fileReference = ($type & self::FILE_REFERENCE_FLAG ? $reader->tgreadBytes() : null);
		$url = ($type & self::WEB_LOCATION_FLAG ? $reader->tgreadBytes() : null);
		$type &= ~ self::FILE_REFERENCE_FLAG;
		$type &= ~ self::WEB_LOCATION_FLAG;
		if($type < 0 or $type >= FileIdType::SIZE->toId()):
			Logging::log('FileId','Invalid FileType !',E_ERROR);
		endif;
		if(is_null($url) === false):
			$accessHash = $reader->readLong();
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
			$data = array(
				'version'=>$version,
				'sub_version'=>$subVersion,
				'dc_id'=>$dc,
				'file_reference'=>$fileReference,
				'url'=>$url,
				'access_hash'=>$accessHash,
				'input_location'=>$input
			);
			$anonymous = new class($data) extends Instance {
				public function download(string $path) : string {
					return $this->download_web_document($path,$this->input_location);
				}
			};
			return $anonymous->setClient($this);
		else:
			$id = $reader->readLong();
			$accessHash = $reader->readLong();
			$input = $this->inputDocumentFileLocation(id : $id,access_hash : $accessHash,file_reference : $fileReference,thumb_size : strval(null));
		endif;
		$volume_id = null;
		$local_id = null;
		$secret = null;
		if($type <= FileIdType::PHOTO->toId()):
			if($subVersion >= self::REMOVE_PHOTO_VOLUME_AND_LOCALID):
				$source = $reader->readInt();
			else:
				$volume_id = $reader->readLong();
				if($subVersion >= self::ADD_PHOTO_SIZE_SOURCE):
					# PhotoSizeType::LEGACY #
					$source = $reader->readInt();
				else:
					# PhotoSizeType::FULL_LEGACY #
					$source = PhotoSizeType::FULL_LEGACY->value;
				endif;
			endif;
			$photoSizeType = PhotoSizeType::from($source);
			switch($photoSizeType):
				case PhotoSizeType::LEGACY:
					$secret = $reader->readLong();
					$local_id = $reader->readInt();
					$input = $this->inputPhotoLegacyFileLocation(id : $id,access_hash : $accessHash,file_reference : $fileReference,volume_id : $volume_id,local_id : $local_id,secret : $secret);
					break;
				case PhotoSizeType::THUMBNAIL:
					$type = $reader->readInt();
					$input->thumb_size = chr($reader->readInt());
					break;
				case PhotoSizeType::DIALOGPHOTO_SMALL:
				case PhotoSizeType::DIALOGPHOTO_BIG:
					$dialogId = $reader->readLong();
					$dialogAccessHash = $reader->readLong();
					$input = $this->inputPeerPhotoFileLocation(peer : $this->get_input_peer($dialogId,$dialogAccessHash),photo_id : $id,big : ($photoSizeType === PhotoSizeType::DIALOGPHOTO_BIG ? true : null));
					break;
				case PhotoSizeType::STICKERSET_THUMBNAIL:
					$stickerSetId = $reader->readLong();
					$stickerSetAccessHash = $reader->readLong();
					$input = $this->inputStickerSetThumb(stickerset : $this->inputStickerSetID(id : $stickerSetId,access_hash : $stickerSetAccessHash),thumb_version : 0);
					break;
				case PhotoSizeType::FULL_LEGACY:
					$secret = $reader->readLong();
					$local_id = $reader->readInt();
					$input = $this->inputPhotoLegacyFileLocation(id : $id,access_hash : $accessHash,file_reference : $fileReference,volume_id : $volume_id,local_id : $local_id,secret : $secret);
					break;
				case PhotoSizeType::DIALOGPHOTO_SMALL_LEGACY:
				case PhotoSizeType::DIALOGPHOTO_BIG_LEGACY:
					$dialogId = $reader->readLong();
					$dialogAccessHash = $reader->readLong();
					$input = $this->inputPeerPhotoFileLocation(peer : $this->get_input_peer($dialogId,$dialogAccessHash),photo_id : $id,big : ($photoSizeType === PhotoSizeType::DIALOGPHOTO_BIG_LEGACY ? true : null));
					break;
				case PhotoSizeType::STICKERSET_THUMBNAIL_LEGACY:
					$stickerSetId = $reader->readLong();
					$stickerSetAccessHash = $reader->readLong();
					$input = $this->inputStickerSetThumb(stickerset : $this->inputStickerSetID(id : $stickerSetId,access_hash : $stickerSetAccessHash),thumb_version : 0);
					break;
				case PhotoSizeType::STICKERSET_THUMBNAIL_VERSION:
					$stickerSetId = $reader->readLong();
					$stickerSetAccessHash = $reader->readLong();
					$stickerThumbVersion = $reader->readInt();
					$input = $this->inputStickerSetThumb(stickerset : $this->inputStickerSetID(id : $stickerSetId,access_hash : $stickerSetAccessHash),thumb_version : $stickerThumbVersion);
					break;
			endswitch;
			if(is_null($local_id) and $subVersion < self::REMOVE_PHOTO_VOLUME_AND_LOCALID):
				$local_id = $reader->readInt();
			endif;
		endif;
		$x = strlen($reader->read());
		$x -= intval($version >= 4 ? 2 : 1);
		if($x > 0):
			Logging::log('FileId','File ID '.$file_id.' has '.strval($x).' bytes left !',E_WARNING);
		endif;
		$data = array(
			'version'=>$version,
			'sub_version'=>$subVersion,
			'dc_id'=>$dc,
			'file_reference'=>$fileReference,
			'file_type'=>FileIdType::fromId($type),
			'id'=>$id,
			'access_hash'=>$accessHash,
			'volume_id'=>$volume_id,
			'local_id'=>$local_id,
			'input_location'=>$input
		);
		$anonymous = new class($data) extends Instance {
			public function download(string $path,? callable $progresscallback = null,? string $key = null,? string $iv = null) : string {
				return $this->download_file($path,PHP_INT_MAX,$this->dc_id,$this->input_location,$progresscallback,$key,$iv);
			}
		};
		return $anonymous->setClient($this);
	}
	public function toBotAPI(FileIdType $file_type,int $dc_id,object $input_location,int $version = 4,int $sub_version = 54) : string {
		$type = $file_type->toId();
		if(isset($input_location->file_reference)):
			$type |= self::FILE_REFERENCE_FLAG;
		endif;
		if(isset($input_location->url)):
			$type |= self::WEB_LOCATION_FLAG;
		endif;
		$writer = new Binary();
		$writer->writeInt($type);
		$writer->writeInt($dc_id);
		if(isset($input_location->file_reference)):
			$writer->tgwriteBytes($input_location->file_reference);
		endif;
		if(isset($input_location->url)):
			$writer->tgwriteBytes($input_location->url);
			$writer->writeLong(0);
			if($version === 4):
				$writer->write(chr($sub_version));
			endif;
			$writer->write(chr($version));
			$file = $writer->read();
			return Tools::base64_url_encode(Rle::encode($file));
		endif;
		switch($input_location->getClass()):
			case 'inputDocumentFileLocation':
				$writer->writeLong($input_location->id);
				$writer->writeLong($input_location->access_hash);
				if(empty($input_location->thumb_size) === false):
					$writer->writeInt(PhotoSizeType::THUMBNAIL->value);
					$writer->writeInt($file_type->toId());
					$writer->writeInt(ord($input_location->thumb_size));
				endif;
				break;
			case 'inputPhotoLegacyFileLocation':
				$writer->writeLong($input_location->id);
				$writer->writeLong($input_location->access_hash);
				$writer->writeLong($input_location->volume_id);
				$writer->writeLong($input_location->secret);
				$writer->writeInt($input_location->local_id);
				break;
			case 'inputPeerPhotoFileLocation':
				$writer->writeLong($input_location->photo_id);
				$writer->writeLong(0);
				$writer->writeInt($input_location->big ? PhotoSizeType::DIALOGPHOTO_BIG->value : PhotoSizeType::DIALOGPHOTO_SMALL->value);
				$writer->writeLong(match($input_location->peer->getClass()){
					'inputPeerEmpty' => 0,
					'inputPeerSelf' => intval($this->get_peer('me')->id),
					'inputPeerChat' => $input_location->peer->chat_id,
					'inputPeerUser' => $input_location->peer->user_id,
					'inputPeerChannel' => $input_location->peer->channel_id
				});
				$writer->writeLong(match($input_location->peer->getClass()){
					'inputPeerEmpty' => 0,
					'inputPeerSelf' => intval($this->get_peer('me')->access_hash),
					'inputPeerChat' => 0,
					'inputPeerUser' => $input_location->peer->access_hash,
					'inputPeerChannel' => $input_location->peer->access_hash
				});
				break;
			case 'inputStickerSetThumb':
				$writer->writeLong(0);
				$writer->writeLong(0);
				$writer->writeInt($input_location->thumb_version === 0 ? PhotoSizeType::STICKERSET_THUMBNAIL->value : PhotoSizeType::STICKERSET_THUMBNAIL_VERSION->value);
				$writer->writeLong($input_location->stickerset->id);
				$writer->writeLong($input_location->stickerset->access_hash);
				if($input_location->thumb_version !== 0):
					$writer->writeInt($input_location->thumb_version);
				endif;
				break;
			default:
				throw new \InvalidArgumentException('The input location '.$input_location->getClass().' is not valid');
		endswitch;
		if($version >= 4):
			$writer->write(chr($sub_version));
		endif;
		$writer->write(chr($version));
		$file = $writer->read();
		return Tools::base64_url_encode(Rle::encode($file));
	}
}

?>