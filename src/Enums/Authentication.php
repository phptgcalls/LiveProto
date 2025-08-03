<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum Authentication : int {
	case NEEDAUTHENTICATION = 0;
	case NEEDCODE = 1;
	case NEEDPASSWORD = 2;
	case LOGIN = 3;
}

?>