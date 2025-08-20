<?php

declare(strict_types = 1);

namespace Tak\Liveproto\Database;

use Tak\Liveproto\Utils\Tools;

use Tak\Liveproto\Utils\Logging;

use Revolt\EventLoop;

use Amp\Sync\LocalMutex;

use Amp\Mysql\MysqlConnectionPool;

final class MySQL implements AbstractDB , AbstractPeers {
	protected object $connection;

	public function __construct(object $config){
		$this->connection = new MysqlConnectionPool($config);
	}
	public function init(string $table) : bool {
		if($this->connection->query('SHOW TABLES LIKE '.chr(39).$table.chr(39))->fetchRow() and $this->connection->query('SELECT * FROM '.$table)->fetchRow()):
			return false;
		else:
			$this->connection->query(
				'CREATE TABLE IF NOT EXISTS '.$table.' (
				`id` BIGINT PRIMARY KEY
				) default charset = utf8mb4'
			);
			return true;
		endif;
	}
	public function set(string $table,string $key,mixed $value,string $type) : void {
		static $mutex = new LocalMutex;
		$lock = $mutex->acquire();
		try {
			if($this->exists($table,$key) === false):
				if($key === 'id'):
					$this->connection->prepare('INSERT IGNORE INTO '.$table.' (`id`) VALUES (:id)')->execute(['id'=>$value]);
				else:
					$this->connection->query('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS '.$key.chr(32).$type);
				endif;
			endif;
			$this->connection->prepare('UPDATE '.$table.' SET '.$key.' = :new')->execute(['new'=>$value]);
		} catch(\Throwable $error){
			Logging::log('MySQL',$error->getMessage(),E_WARNING);
		} finally {
			EventLoop::queue($lock->release(...));
		}
	}
	public function get(string $table) : array | null {
		return $this->connection->query('SELECT * FROM '.$table)->fetchRow();
	}
	public function delete(string $table,string $key) : void {
		$this->connection->query('ALTER TABLE '.$table.' DROP COLUMN '.$key);
	}
	public function exists(string $table,string $key) : bool {
		$columns = $this->connection->query('SHOW COLUMNS FROM '.$table)->fetchRow();
		$fields = array_column($columns,'Field');
		return in_array($key,$fields);
	}
	public function initPeer(string $table) : bool {
		if($this->connection->query('SHOW TABLES LIKE '.chr(39).$table.chr(39))->fetchRow()):
			return false;
		else:
			$this->connection->query(
				'CREATE TABLE IF NOT EXISTS '.$table.' (
				`id` BIGINT PRIMARY KEY
				) default charset = utf8mb4'
			);
			return true;
		endif;
	}
	public function setPeer(string $table,mixed $value) : void {
		static $mutex = new LocalMutex;
		$lock = $mutex->acquire();
		try {
			$keys = array_keys($value);
			$values = array_values($value);
			$this->connection->query(
				implode(chr(32),array(
					'ALTER TABLE '.$table,
					implode(chr(44),array_map(fn(string $key,mixed $value) : string => strval('ADD COLUMN IF NOT EXISTS `'.$key.'` '.Tools::inferType($value)),$keys,$values))
				))
			);
			$this->connection->prepare(
				implode(chr(32),array(
					'INSERT INTO '.$table,
					'(`'.implode(chr(96).chr(44).chr(96),$keys).'`)',
					'VALUES',
					'('.chr(58).implode(chr(44).chr(58),$keys).')',
					'ON DUPLICATE KEY UPDATE',
					implode(chr(44),array_map(fn(string $key) : string => strval($key.' = VALUES('.$key.')'),$keys))
				))
			)->execute($value);
		} catch(\Throwable $error){
			Logging::log('MySQL',$error->getMessage(),E_WARNING);
		} finally {
			EventLoop::queue($lock->release(...));
		}
	}
	public function getPeer(string $table,string $key,mixed $value) : array | null {
		return $this->connection->prepare('SELECT * FROM '.$table.' WHERE '.$key.' = :value')->execute(['value'=>$value])->fetchRow();
	}
	public function deletePeer(string $table,string $key,mixed $value) : void {
		$this->connection->query('DELETE FROM '.$table.' WHERE '.$key.' = :value')->execute(['value'=>$value]);
	}
}

?>