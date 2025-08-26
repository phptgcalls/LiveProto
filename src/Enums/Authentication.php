<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum Authentication : int {
	case NEED_AUTHENTICATION = 0;
	case NEED_CODE = 1;
	case NEED_CODE_PAYMENT_REQUIRED = 2;
	case NEED_EMAIL = 3;
	case NEED_EMAIL_VERIFY = 4;
	case NEED_PASSWORD = 5;
	case NEED_SIGNUP = 6;
	case LOGIN = 7;
}

?>