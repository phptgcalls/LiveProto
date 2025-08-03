<?php

declare(strict_types = 1);

require realpath(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'autoload.php');

use Tak\Liveproto\Tl\Generator;

use function Amp\File\write;

Generator::start();

$errorsfile = strval(__DIR__.DIRECTORY_SEPARATOR.'Utils'.DIRECTORY_SEPARATOR.'errors.json');

$errorscontent = json_decode(file_get_contents('https://core.telegram.org/file/400780400470/3/OY6JMkb69K4.143326.json/3c10f72ff9ce45e8a9'),flags : JSON_THROW_ON_ERROR);

write($errorsfile,json_encode($errorscontent->descriptions,flags : JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

?>