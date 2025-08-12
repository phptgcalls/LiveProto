# Database

?> Note, The session and information about the peers must be saved somewhere, so here we want to introduce various data saving modes

## MySQL

MySQL database is currently the most optimal way and I recommend it to you

```php
use Tak\Liveproto\Network\Client;

use Tak\Liveproto\Utils\Settings;

$settings = new Settings();

/* Telegram Settings */
$settings->setApiId(29784714);
$settings->setApiHash('143dfc3c92049c32fbc553de2e5fb8e4');

/* If you want to use MySQL */
$settings->setServer('localhost');
$settings->setUsername('Username');
$settings->setPassword('Password');
$settings->setDatabase('DatabaseName');

$client = new Client('YourSessionName','mysql',$settings);
```

## String

So in this case, your database is stored as a single string in a file with the extension `.session`, very efficient for a quick start

> [!TIP]
> You can give a file path for the session name here ( like : `./folder/file` )

```php
use Tak\Liveproto\Network\Client;

use Tak\Liveproto\Utils\Settings;

$settings = new Settings();

/* Telegram Settings */
$settings->setApiId(29784714);
$settings->setApiHash('143dfc3c92049c32fbc553de2e5fb8e4');

$client = new Client('YourSessionName','string',$settings);
```

## Text

After launching and logging in, you can get the entire session as a text string and use it instead of the session name and save it wherever you want

```php
use Tak\Liveproto\Network\Client;

use Tak\Liveproto\Utils\Settings;

$settings = new Settings();

/* Telegram Settings */
$settings->setApiId(29784714);
$settings->setApiHash('143dfc3c92049c32fbc553de2e5fb8e4');

$client = new Client('YourSessionName','string',$settings);

/*
 * Your client must be authenticated here
 */
 
if($client->isAuthorized()){

	/* For example, save this string anywhere you want */
	$textString = strval($client);

	/* Then grab the same textString from wherever you saved it and use it */
	$client = new Client($textString,'text',$settings);
}
```

### Task List
- [x] Providing infrastructure for adding custom databases by the user
  - [ ] Add it to client settings
- [ ] Support for various databases
  - [ ] Postgresql
  - [ ] SQLite3
  - [ ] MongoDB