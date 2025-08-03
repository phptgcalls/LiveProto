<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum FileIdType : string {
	case THUMBNAIL = 'thumbnail';
	case PROFILE_PHOTO = 'profile_photo';
	case PHOTO = 'photo';
	case VOICE = 'voice';
	case VIDEO = 'video';
	case DOCUMENT = 'document';
	case ENCRYPTED = 'encrypted';
	case TEMP = 'temp';
	case STICKER = 'sticker';
	case AUDIO = 'audio';
	case ANIMATION = 'animation';
	case ENCRYPTED_THUMBNAIL = 'encrypted_thumbnail';
	case WALLPAPER = 'wallpaper';
	case VIDEO_NOTE = 'video_note';
	case SECURE_RAW = 'secure_raw';
	case SECURE = 'secure';
	case BACKGROUND = 'background';
	case SIZE = 'size';

	static public function fromId(int $id) : self {
		return self::cases()[$id];
	}
	public function toId() : int {
		return array_search($this,self::cases());
	}
}

?>