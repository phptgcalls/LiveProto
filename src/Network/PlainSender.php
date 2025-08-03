<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Network;

use Tak\Liveproto\Utils\Binary;

final class PlainSender {
	public function __construct(protected object $transport,private readonly object $session){
	}
	public function send(object $request) : void {
		$writer = new Binary();
		$writer->writeLong(0);
		$writer->writeLong($this->session->getNewMsgId());
		$packet = $request->read();
		$writer->writeInt(strlen($packet));
		$writer->write($packet);
		$this->transport->send($writer->read());
	}
	public function receive() : object {
		$body = $this->transport->receive();
		$reader = new Binary();
		$reader->write($body);
		$authKeyId = $reader->readLong();
		$msgId = $reader->readLong();
		$messageLength = $reader->readInt();
		$message = $reader->read($messageLength);
		$reader = new Binary();
		$reader->write($message);
		return $reader->tgreadObject();
	}
	public function __invoke(object $raw,mixed ...$arguments) : object {
		$this->send($raw->request(...$arguments));
		return $this->receive();
	}
}

?>