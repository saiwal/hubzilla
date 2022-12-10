<?php

namespace Zotlabs\Lib;


use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnableToBuildUuidException;


class QueueWorker {

	public static $queueworker = null;
	public static $maxworkers = 0;
	public static $workermaxage = 0;
	public static $workersleep = 100;
	public static $default_priorities = [
		'Notifier'          => 10,
		'Deliver'           => 10,
		'Cache_query'       => 10,
		'Content_importer'  => 1,
		'File_importer'     => 1,
		'Channel_purge'     => 1,
		'Directory'         => 1
	];

	private static function qbegin($tablename) {
		switch (ACTIVE_DBTYPE) {
			case DBTYPE_MYSQL:
				q('BEGIN');
				q('LOCK TABLE ' . $tablename . ' WRITE');
				break;

			case DBTYPE_POSTGRES:
				q('BEGIN');
				//q('LOCK TABLE '.$tablename.' IN ACCESS EXCLUSIVE MODE');
				break;
		}
		return;
	}

	private static function qcommit() {
		switch (ACTIVE_DBTYPE) {
			case DBTYPE_MYSQL:
				q("UNLOCK TABLES");
				q("COMMIT");
				break;

			case DBTYPE_POSTGRES:
				q("COMMIT");
				break;
		}
		return;
	}

	private static function qrollback() {
		switch (ACTIVE_DBTYPE) {
			case DBTYPE_MYSQL:
				q("ROLLBACK");
				q("UNLOCK TABLES");
				break;

			case DBTYPE_POSTGRES:
				q("ROLLBACK");
				break;
		}
		return;
	}

	public static function Summon(&$argv) {

		$argc = count($argv);

		if ($argv[0] !== 'Queueworker') {

			$priority = 0; // @TODO allow reprioritization

			if(isset(self::$default_priorities[$argv[0]])) {
				$priority = self::$default_priorities[$argv[0]];
			}

			$workinfo      = ['argc' => $argc, 'argv' => $argv];
			$workinfo_json = json_encode($workinfo);
			$uuid          = self::getUuid($workinfo_json);

			$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
				dbesc($uuid)
			);
			if ($r) {
				logger("Summon: Ignoring duplicate workerq task", LOGGER_DEBUG);
				logger(print_r($workinfo,true));
				$argv = [];
				return;
			}

			self::qbegin('workerq');
			$r = q("INSERT INTO workerq (workerq_priority, workerq_data, workerq_uuid) VALUES (%d, '%s', '%s')",
				intval($priority),
				$workinfo_json,
				dbesc($uuid)
			);
			if (!$r) {
				self::qrollback();
				logger("INSERT FAILED", LOGGER_DEBUG);
				return;
			}
			self::qcommit();
			logger('INSERTED: ' . $workinfo_json, LOGGER_DEBUG);
		}
		$argv = [];

		$workers = self::GetWorkerCount();
		if ($workers < self::$maxworkers) {
			logger("Less than max active workers ($workers) max = " . self::$maxworkers . ".", LOGGER_DEBUG);
			$phpbin = get_config('system', 'phpbin', 'php');
			proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker']);
		}
	}

	public static function Release(&$argv) {

		$argc = count($argv);

		if ($argv[0] !== 'Queueworker') {

			$priority = 0; // @TODO allow reprioritization
			if(isset(self::$default_priorities[$argv[0]])) {
				$priority = self::$default_priorities[$argv[0]];
			}

			$workinfo      = ['argc' => $argc, 'argv' => $argv];
			$workinfo_json = json_encode($workinfo);
			$uuid          = self::getUuid($workinfo_json);

			$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
				dbesc($uuid)
			);
			if ($r) {
				logger("Release: Duplicate task - do not insert.", LOGGER_DEBUG);
				logger(print_r($workinfo,true));

				$argv = [];
				return;
			}

			self::qbegin('workerq');
			$r = q("INSERT INTO workerq (workerq_priority, workerq_data, workerq_uuid) VALUES (%d, '%s', '%s')",
				intval($priority),
				$workinfo_json,
				dbesc($uuid)
			);
			if (!$r) {
				self::qrollback();
				logger("Insert failed: " . $workinfo_json, LOGGER_DEBUG);
				return;
			}
			self::qcommit();
			logger('INSERTED: ' . $workinfo_json, LOGGER_DEBUG);
		}
		$argv = [];
		self::Process();
	}

	public static function GetWorkerCount() {
		if (self::$maxworkers == 0) {
			self::$maxworkers = get_config('queueworker', 'max_queueworkers', 4);
			self::$maxworkers = self::$maxworkers > 3 ? self::$maxworkers : 4;
		}
		if (self::$workermaxage == 0) {
			self::$workermaxage = get_config('queueworker', 'max_queueworker_age');
			self::$workermaxage = self::$workermaxage > 120 ? self::$workermaxage : 300;
		}

		q("update workerq set workerq_reservationid = null where workerq_reservationid is not null and workerq_processtimeout < %s",
			db_utcnow()
		);

		usleep(self::$workersleep);
		$workers = dbq("select count(distinct workerq_reservationid) as total from workerq where workerq_reservationid is not null");
		logger("WORKERCOUNT: " . $workers[0]['total'], LOGGER_DEBUG);
		return intval($workers[0]['total']);
	}

	public static function GetWorkerID() {
		if (self::$queueworker) {
			return self::$queueworker;
		}
		$wid = uniqid('', true);
		usleep(mt_rand(500000, 3000000)); //Sleep .5 - 3 seconds before creating a new worker.
		$workers = self::GetWorkerCount();
		if ($workers >= self::$maxworkers) {
			logger("Too many active workers ($workers) max = " . self::$maxworkers, LOGGER_DEBUG);
			return false;
		}
		self::$queueworker = $wid;
		return $wid;
	}

	private static function getWorkId() {
		self::GetWorkerCount();

		self::qbegin('workerq');

		if (ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$work = dbq("SELECT workerq_id FROM workerq WHERE workerq_reservationid IS NULL ORDER BY workerq_priority DESC, workerq_id ASC LIMIT 1 FOR UPDATE SKIP LOCKED;");
		}
		else {
			$work = dbq("SELECT workerq_id FROM workerq WHERE workerq_reservationid IS NULL ORDER BY workerq_priority DESC, workerq_id ASC LIMIT 1;");
		}

		if (!$work) {
			self::qrollback();
			return false;
		}
		$id = $work[0]['workerq_id'];

		$work = q("UPDATE workerq SET workerq_reservationid = '%s', workerq_processtimeout = %s + INTERVAL %s WHERE workerq_id = %d",
			self::$queueworker,
			db_utcnow(),
			db_quoteinterval(self::$workermaxage . " SECOND"),
			intval($id)
		);

		if (!$work) {
			self::qrollback();
			logger("Could not update workerq.", LOGGER_DEBUG);
			return false;
		}
		logger("GOTWORK: " . json_encode($work), LOGGER_DEBUG);
		self::qcommit();
		return $id;
	}

	public static function Process() {
		if (!self::GetWorkerID()) {
			logger('Unable to get worker ID. Exiting.', LOGGER_DEBUG);
			killme();
		}

		$jobs   = 0;
		$workid = self::getWorkId();
		$load_average_sleep = false;
		self::$workersleep = get_config('queueworker', 'queue_worker_sleep');
		self::$workersleep = ((intval(self::$workersleep) > 100) ? intval(self::$workersleep) : 100);

		if (function_exists('sys_getloadavg') && get_config('queueworker', 'load_average_sleep')) {
			$load_average_sleep = true;
		}

		while ($workid) {

			if ($load_average_sleep) {
				$load_average = sys_getloadavg();
				self::$workersleep = intval($load_average[0]) * 100000;

				if (!self::$workersleep) {
					self::$workersleep = 100;
				}
			}

			usleep(self::$workersleep);

			self::qbegin('workerq');

			if (ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
				$workitem = q("SELECT * FROM workerq WHERE workerq_id = %d FOR UPDATE SKIP LOCKED",
					$workid
				);
			}
			else {
				$workitem = q("SELECT * FROM workerq WHERE workerq_id = %d",
					$workid
				);
			}

			self::qcommit();

			if (isset($workitem[0])) {
				// At least SOME work to do.... in case there's more, let's ramp up workers.
				$workers = self::GetWorkerCount();
				if ($workers < self::$maxworkers) {
					logger("Less than max active workers ($workers) max = " . self::$maxworkers . ".", LOGGER_DEBUG);
					$phpbin = get_config('system', 'phpbin', 'php');
					proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker']);
				}

				$jobs++;
				logger("Workinfo: " . $workitem[0]['workerq_data'], LOGGER_DEBUG);

				$workinfo = json_decode($workitem[0]['workerq_data'], true);
				$argv     = $workinfo['argv'];
				hz_syslog('Master: process: ' . json_encode($argv), LOGGER_DEBUG);

				$cls  = '\\Zotlabs\\Daemon\\' . $argv[0];
				$argv = flatten_array_recursive($argv);
				$argc = count($argv);
				$cls::run($argc, $argv);

				// @FIXME: Right now we assume that if we get a return, everything is OK.
				// At some point we may want to test whether the run returns true/false
				// and requeue the work to be tried again if needed.  But we probably want
				// to implement some sort of "retry interval" first.

				self::qbegin('workerq');
				q("delete from workerq where workerq_id = %d", $workid);
				self::qcommit();
			}
			else {
				logger("NO WORKITEM!", LOGGER_DEBUG);
			}
			$workid = self::getWorkId();
		}
		logger('Master: Worker Thread: queue items processed:' . $jobs, LOGGER_DEBUG);
	}

	public static function ClearQueue() {
		$work = q("select * from workerq");
		while ($work) {
			foreach ($work as $workitem) {
				$workinfo = json_decode($workitem['v'], true);
				$argc     = $workinfo['argc'];
				$argv     = $workinfo['argv'];
				logger('Master: process: ' . print_r($argv, true), LOGGER_ALL, LOG_DEBUG);
				if (!isset($argv[0])) {
					q("delete from workerq where workerq_id = %d",
						$work[0]['workerq_id']
					);
					continue;
				}
				$cls = '\\Zotlabs\\Daemon\\' . $argv[0];
				$cls::run($argc, $argv);
				q("delete from workerq where workerq_id = %d",
					$work[0]['workerq_id']
				);
				usleep(300000);
				//Give the server .3 seconds to catch its breath between tasks.
				//This will hopefully keep it from crashing to it's knees entirely
				//if the last task ended up initiating other parallel processes
				//(eg. polling remotes)
			}
			//Make sure nothing new came in
			$work = q("select * from workerq");
		}
		return;
	}

	/**
	 * @brief Generate a name-based v5 UUID with custom namespace
	 *
	 * @param string $data
	 * @return string $uuid
	 */
	private static function getUuid($data) {
		$namespace = '3a112e42-f147-4ccf-a78b-f6841339ea2a';
		try {
			$uuid = Uuid::uuid5($namespace, $data)->toString();
		} catch (UnableToBuildUuidException $e) {
			logger('UUID generation failed');
			return '';
		}
		return $uuid;
	}

}
