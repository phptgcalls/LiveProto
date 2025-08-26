<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum NonRpcResult : string {
	case DESTROY_SESSION = 'destroySession';
	case DESTROY_AUTH_KEY = 'destroyAuthKey';
}

?>