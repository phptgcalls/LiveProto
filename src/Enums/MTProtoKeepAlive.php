<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum MTProtoKeepAlive : string {
	case PING_PONG = 'ping-pong'; // Primary application-level keepalive for MTProto : ping ( client ) <-> pong ( server ) //
	case HTTP_LONG_POLL = 'http-long-poll'; // HTTP long-polling ( e.g. http_wait / long polling style transports ) //
	case NONE = 'none'; // No persistent keepalive ( one-shot connections ) //
}

?>