<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Enums;

enum EmailPurpose : string {
	case LOGINSETUP = 'emailVerifyPurposeLoginSetup';
	case LOGINCHANGE = 'emailVerifyPurposeLoginChange';
	case PASSPORT = 'emailVerifyPurposePassport';
}

?>