<?php

declare(strict_types = 1);

defined('CRLF') || define('CRLF',chr(13).chr(10));

defined('LFCR') || define('LFCR',chr(10).chr(13));

class_alias(Tak\Liveproto\Network\Client::class,Tak\Liveproto\API::class);

class_alias(Tak\Liveproto\Filters\Filter::class,Tak\Liveproto\Handler::class);

class_alias(Tak\Liveproto\Errors\RpcError::class,Tak\Liveproto\Errors::class);

?>