<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

# https://github.com/tdlib/td/blob/master/td/telegram/PhotoSizeSource.h #
enum PhotoSizeType : int {
	case LEGACY = 0;
	case THUMBNAIL = 1;
	case DIALOGPHOTO_SMALL = 2;
	case DIALOGPHOTO_BIG = 3;
	case STICKERSET_THUMBNAIL = 4;
	case FULL_LEGACY = 5;
	case DIALOGPHOTO_SMALL_LEGACY = 6;
	case DIALOGPHOTO_BIG_LEGACY = 7;
	case STICKERSET_THUMBNAIL_LEGACY = 8;
	case STICKERSET_THUMBNAIL_VERSION = 9;
}

?>