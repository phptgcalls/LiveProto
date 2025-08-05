<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

# https://github.com/tdlib/td/blob/master/td/telegram/files/FileType.h #
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
	case SECURE_DECRYPTED = 'secure_decrypted';
	case SECURE_ENCRYPTED = 'secure_encrypted';
	case BACKGROUND = 'background';
	case DOCUMENT_AS_FILE = 'document_as_file';
	case RINGTONE = 'ringtone';
	case CALL_LOG = 'call_log';
	case PHOTO_STORY = 'photo_story';
	case VIDEO_STORY = 'video_story';
	case SELF_DESTRUCTING_PHOTO = 'self_destructing_photo';
	case SELF_DESTRUCTING_VIDEO = 'self_destructing_video';
	case SELF_DESTRUCTING_VIDEONOTE = 'self_destructing_videonote';
	case SELF_DESTRUCTING_VOICENOTE = 'self_destructing_voicenote';
	case SIZE = 'size';

	static public function fromId(int $id) : self {
		return self::cases()[$id] ?? throw new \InvalidArgumentException('Invalid FileIdType ID !');
	}
	public function toId() : int {
		return array_search($this,self::cases());
	}
}

?>