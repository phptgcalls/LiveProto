<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum RekeyState : int {
	case IDLE = 0;
	case REQUESTED = 1;
	case ACCEPTED = 2;
}

?>