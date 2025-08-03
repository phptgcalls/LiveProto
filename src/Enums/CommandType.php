<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum CommandType : string {
	case DOT = '.';
	case SLASH = '/';
	case BACKSLASH = '\\';
	case EXCLAMATION = '!';
	case COLON = ':';
	case SEMICOLON = ';';
	case HASH = '#';
	case DOLLAR = '$';
	case AMPERSAND = '&';
	case ASTERISK = '*';
	case CARET = '^';
	case TILDE = '~';
	case PIPE = '|';
	case AT = '@';
}

?>