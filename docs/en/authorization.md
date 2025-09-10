# Authorization

When you create a [client object](en/quickstart.md), you still need to do some work to make API calls. This section provides all the information you need in order to authorize yourself as user or bot

---

## User Authorization

If you use the start method and if you have not done user authorization, the <mark>LiveProto</mark> will receive your phone number and account login code ( and password if you have a password on your account )

!> This feature is possible both via the WEB and via the CLI

```php
$client->start();
```

> Instead of `$client->start();` You can also use it as rest API and use the following methods `$client->connect();` and `$client->disconnect();`

### Send Code

```php
$client->connect();

try {
	var_dump($client->send_code('+1234567890'));
} catch(\Throwable $error){
	var_dump($error);
} finally {
	$client->disconnect();
}
```

### Sign In

```php
$client->connect();

try {
	var_dump($client->sign_in(code : '12345'));
} catch(\Throwable $e){
	var_dump($e);
	if($error->getMessage() === 'SESSION_PASSWORD_NEEDED'){
		// go to the next step //
	}
} finally {
	$client->disconnect();
}
```

!> If you had an error when signing in and it was related to the fact that your account has a password, then go to the next step

### Password

```php
$client->connect();

try {
	var_dump($client->sign_in(password : 'HelloWorld'));
} catch(\Throwable $error){
	var_dump($error);
} finally {
	$client->disconnect();
}
```

---

## Bot Authorization

Bots are a special kind of users that are authorized via their tokens ( instead of phone numbers ), which are created by the [Bot Father](https://t.me/BotFather)

!> This feature is possible both via the WEB and via the CLI

```php
$client->start();
```

> Very simple, you can use the `sign_in` method again to login via bot

### Sign In

```php
$client->connect();

try {
	var_dump($client->sign_in(bot_token : '4839574812:AAFD39kkdpWt3ywyRZergyOLMaJhac60qc'));
} catch(\Throwable $e){
	var_dump($e);
} finally {
	$client->disconnect();
}
```

?> Note, After the first authorization, you no longer need to put the following parameters in your [`configuration`](en/configuration.md#Settings) because they are saved ( ~~ApiId~~ , ~~ApiHash~~ ) **This will work if your [Session Name](en/quickstart.md#ResourceName) is not _Null_**