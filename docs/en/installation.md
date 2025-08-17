# Installation

You can install the library and use it in two ways, the first way is to use Phar File and the second way is to use Composer

---

## Phar

If you want to have a quick and easy start, it is recommended to use this method, you can download library in the form of a single file as `liveproto.php`

?> Note, You can also use a file to automatically download the latest phar file for you and even update it automatically (`auto-update`) and include it

```php
<?php

if(file_exists('liveproto.php') === false):
    copy('https://installer.liveproto.dev/liveproto.php','liveproto.php');
endif;

require_once 'liveproto.php';
```

?> Note, By changing `latest` to the version you want, you can use any version for example :

```php
<?php

define('LP_VERSION','0.0.3');

if(file_exists('liveproto.php') === false):
    copy('https://installer.liveproto.dev/liveproto.php','liveproto.php');
endif;

require_once 'liveproto.php';
```

OR

```php
<?php

if(file_exists('liveproto-v0.0.3.phar') === false):
    copy('https://phar.liveproto.dev/v0.0.3/liveproto.phar','liveproto-v0.0.3.phar');
endif;

require_once 'liveproto-v0.0.3.phar';
```

---

## Composer

You can also use the following command line to install the library

> _This is the best way_

```bash
composer require taknone/liveproto
```

!> **Composer v2+ is required !**

And finally, follow the code below to use the library

```php
<?php

require_once 'vendor/autoload.php';
```

---

### Composer from scratch 

> `composer.json` file content :

```json
{
    "name": "yourname/yourproject",
    "description": "Project Description",
    "type": "project",
    "autoload": {
        "psr-4": {
            "Yourname\\Yourproject\\": "src/"
        }
    },
    "authors": [
        {
            "name": "Tak None",
            "email": "MrTakNone@gmail.com"
        }
    ],
    "require": {
        "php": ">=8.2",
        "taknone/liveproto": "*"
    }
}
```

> Then run this command line

```bash
composer update
```