# LiveProto

An **async** , **pure-PHP** MTProto Telegram client library , offering full protocol support without any native extensions

---

## 🚀 Features

* **Full MTProto Protocol :** Complete implementation of Telegram's low-level protocol
* **Asynchronous I/O :** Built with PHP 8's async primitives (Fibers/Amp), enabling non-blocking requests
* **Zero External Dependencies :** No PECL extensions or native libraries required
* **Session Management :** Automatic key exchange, session storage, and reconnection logic
* **Comprehensive API Coverage :** Send and receive messages, manage chats and channels, handle updates, upload/download media, and more

---

## 📦 Installation

Install via Composer :

```bash
composer require taknone/liveproto
```

---

## 🏁 Getting Started

Example Usage :

```php
<?php

require 'vendor/autoload.php';

use Tak\Liveproto\Network\Client;

use Tak\Liveproto\Utils\Settings;

$settings = new Settings();
$settings->setApiId(21724);
$settings->setApiHash('3e0cb5efcd52300aec5994fdfc5bdc16');
$settings->setHideLog(false);

$client = new Client('test','string',$settings);

$client->connect();

try {
	if($client->isAuthorized() === false){
		$client->sign_in(token : '123456:AAEK.....');
	}
	$peer = $client->get_input_peer('@TakNone');
	print_r($client->messages->sendMessage($peer,'👋',random_int(PHP_INT_MIN,PHP_INT_MAX)));
} catch(Throwable $error){
	var_dump($error);
} finally {
	$client->disconnect();
}

?>
```

---

## 🎓 Documentation

Visit [docs LiveProto](https://docs.LiveProto.dev)

## 📜 License

This project is licensed under the [MIT License](LICENSE)
