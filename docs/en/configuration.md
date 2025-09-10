# Configuration

> Here we want to explain to you the configuration of the settings of a <mark>LiveProto Client</mark>

---

## Settings

First of all, you need to adjust its settings to your liking

```php
use Tak\Liveproto\Utils\Settings;

$settings = new Settings();


/* Telegram Settings */
$settings->setApiId(29784714);
$settings->setApiHash('143dfc3c92049c32fbc553de2e5fb8e4');
$settings->setDeviceModel('PC 64bit');
$settings->setSystemVersion('4.14.186');
$settings->setAppVersion('1.28.5');
$settings->setSystemLangCode('en-US');
$settings->setLangPack('android');
$settings->setLangCode('en-US');


/* LiveProto Settings ( optional ) */
$settings->setHotReload(false);
$settings->setFloodSleepThreshold(120);
$settings->setReceiveUpdates(false);
$settings->setIPv6(true);
$settings->setTestMode(false);
$settings->setDC(1);
$settings->setProtocol(Tak\Liveproto\Enums\ProtocolType::TcpFull);
$settings->setProxy(type : 'socks5',address : '127.0.0.1:443',username : 'ProxyUser',password : 'ProxyPassword');
$settings->setTakeout(message_users : true,message_chats : true,message_megagroups : true,message_channels : true);
$params = new \Tak\Liveproto\Tl\Types\Other\JsonObject([
	'value'=>array(
		new \Tak\Liveproto\Tl\Types\Other\JsonObjectValue([
			'key'=>'tz_offset',
			'value'=>new \Tak\Liveproto\Tl\Types\Other\JsonNumber([
				'value'=>(float) (new DateTime('now',new DateTimeZone(date_default_timezone_get())))->getOffset()
			])
		])
	)
]);
$settings->setParams($params);
$settings->setSaveTime(3);
$settings->setHideLog(false);
$settings->setMaxSizeLog(100 * 1024 * 1024);
$settings->setPathLog('Liveproto.log');


/* If you want to use MySQL */
$settings->setServer('localhost');
$settings->setUsername('Username');
$settings->setPassword('Password');
$settings->setDatabase('DatabaseName');
```

---

## API ID

- Type : `Integer` <kbd style="color : red">required</kbd>
- Default : `21724`

If this parameter is not set, the api id of an official client is used by default, The API ID you obtained from https://my.telegram.org

!> If your client is not [official](en/configuration.md#Params), using the **API ID** of an [official](en/configuration.md#Params) client is dangerous

## API HASH

- Type : `String` <kbd style="color : red">required</kbd>
- Default : `3e0cb5efcd52300aec5994fdfc5bdc16`

If this parameter is not set, the api hash of an official client is used by default, The API HASH you obtained from https://my.telegram.org

!> If your client is not [official](en/configuration.md#Params), using the **API HASH** of an [official](en/configuration.md#Params) client is dangerous

## Device Model

- Type : `String` <kbd>optional</kbd>
- Default : `$OperatingSystemName`

Defaults to php_uname('s')

## System Version

- Type : `String` <kbd>optional</kbd>
- Default : `$ReleaseName`

Defaults to php_uname('r')

## App Version

- Type : `String` <kbd>optional</kbd>
- Default : `0.26.8.1721-universal`

Default is an [official](en/configuration.md#Params) app version

## System Lang Code

- Type : `String` <kbd>optional</kbd>
- Default : `$Locale`

If the intl extension is enable, it will be used, otherwise the default value is 'en-US'

## Lang Pack

- Type : `String` <kbd>optional</kbd>
- Default : `android`

!> **If you want to use this feature, you must have an [official](en/configuration.md#Params) client !**

## Lang Code

- Type : `String` <kbd>optional</kbd>
- Default : `$Locale`

If the intl extension is enable, it will be used, otherwise the default value is 'en'

## Hot Reload

- Type : `Boolean` <kbd>optional</kbd>
- Default : `True`

If this parameter is set to false, The previous process creates a lock on the session, preventing it from being executed again

?> Note, If false, To be more clear, it executes one process, keeps one in the queue, and cancels subsequent executions OR If true, Every time you run the script, the previous process is disconnected and killed, and a new process is run with the new script and updated code

## Flood Sleep Threshold

- Type : `Integer` <kbd>optional</kbd>
- Default : `120`

If you encounter a FLOOD error, and if it is less than the set value, it will resend the request after X seconds, and if the flood wait time is more than the set time, you will get an error

## Receive Updates

- Type : `Boolean` <kbd>optional</kbd>
- Default : `True`

If this parameter is set to false,use invokeWithoutUpdates(query = initConnection), If you give false value, your session will no longer receive updates

## IPv6

- Type : `Boolean` <kbd>optional</kbd>
- Default : `False`

If its value is false, it uses Telegram IP version 4 (ipv4), and if it is true, it uses Telegram IP version 6 (ipv6)

!> Note, You cannot change it later ! For the first time, everything you set is no longer changeable

## Takeout

- Type : `...Mixed` <kbd>optional</kbd>
- Default : `False`

You can pass anything that method [initTakeoutSession](https://tl.liveproto.dev/#/method/account.initTakeoutSession) accepts as a parameter

!> Note, You can also provide an empty array to enable it , like `$settings->setTakeout(array())`

## Test Mode

- Type : `Boolean` <kbd>optional</kbd>
- Default : `False`

If this parameter is set to true, you will be connected to Telegram's test servers

## DC

- Type : `Integer` <kbd>optional</kbd>
- Default : `0 | Random`

Enter the number of the data center you want to connect to

## Protocol

- Type : `ProtocolType` <kbd>optional</kbd>
- Default : `ProtocolType::FULL`

Set the tcp connection [`protocol`](en/enums.md#ProtocolType)

## Proxy

- Type : `...String` <kbd>optional</kbd>

`SOCKS5` and `HTTP` : You must pass `type` and `address` arguments and `username` and `password` parameters are optional

`MTPROXY` : You must pass `type` and `address` and `secret` arguments

!> Possible values ​​for the type parameter : `SOCKS5` , `HTTP` , `MTPROXY`

## Params

- Type : `Object | JSONValue` <kbd>optional</kbd>
- Default : `Null`

This parameter is only used for official clients

## Save Time

- Type : `Integer | Float` <kbd>optional</kbd>
- Default : `3`

The value is in seconds and after this time your information is saved in the database

## Hide Log

- Type : `Boolean` <kbd>optional</kbd>
- Default : `False`

If its value is true, then no more logs will be printed

## Max Size Log

- Type : `Integer` <kbd>optional</kbd>
- Default : `10 * 1024 * 1024 | 10MB`

This parameter must be given in bytes, if the size of your log file reaches this value, it will be deleted automatically

## Path Log

- Type : `String` <kbd>optional</kbd>
- Default : `Liveproto.log`

You can set the log file path in which file the logs are saved

### Server

- Type : `Integer` <kbd>optional</kbd>
- Default : `localhost`

The IP of the server on which the database is to be set

### Username

- Type : `String` <kbd style="color : red">required</kbd>

Your database username

### Password

- Type : `String` <kbd style="color : red">required</kbd>

Your database password

### Database

- Type : `String` <kbd>optional</kbd>
- Default : `$Username`

Your database name, By default, the database username value is set for it