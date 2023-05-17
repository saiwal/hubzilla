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
		'Notifier'         => 10,
		'Deliver'          => 10,
		'Cache_query'      => 10,
		'Content_importer' => 1,
		'File_importer'    => 1,
		'Channel_purge'    => 1,
		'Directory'        => 1
	];

	// Exceptions for processtimeout ($workermaxage) value.
	// Currently the value is overriden with 3600 seconds (1h).
	public static $long_running_cmd = [
		'Queue',
		'Expire'
	];

	private static function qstart() {
		q('START TRANSACTION');
	}

	private static function qcommit() {
		q("COMMIT");
	}

	private static function qrollback() {
		q("ROLLBACK");
	}

	public static function Summon($argv) {

		if ($argv[0] !== 'Queueworker') {

			$priority = 0; // @TODO allow reprioritization

			if (isset(self::$default_priorities[$argv[0]])) {
				$priority = self::$default_priorities[$argv[0]];
			}

			$workinfo      = ['argc' => count($argv), 'argv' => $argv];
			$workinfo_json = json_encode($workinfo);
			$uuid          = self::getUuid($workinfo_json);

			$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
				dbesc($uuid)
			);
			if ($r) {
				logger("Summon: Ignoring duplicate workerq task", LOGGER_DEBUG);
				logger(print_r($workinfo, true));
				return;
			}

			self::qstart();
			$r = q("INSERT INTO workerq (workerq_priority, workerq_data, workerq_uuid, workerq_cmd) VALUES (%d, '%s', '%s', '%s')",
				intval($priority),
				$workinfo_json,
				dbesc($uuid),
				dbesc($argv[0])
			);
			if (!$r) {
				self::qrollback();
				logger("INSERT FAILED", LOGGER_DEBUG);
				return;
			}
			self::qcommit();
			logger('INSERTED: ' . $workinfo_json, LOGGER_DEBUG);
		}

		$workers = self::GetWorkerCount();
		if ($workers < self::$maxworkers) {
			logger($workers . '/' . self::$maxworkers . ' workers active', LOGGER_DEBUG);
			$phpbin = get_config('system', 'phpbin', 'php');
			proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker']);
		}
	}

	public static function Release($argv) {

		if ($argv[0] !== 'Queueworker') {

			$priority = 0; // @TODO allow reprioritization
			if (isset(self::$default_priorities[$argv[0]])) {
				$priority = self::$default_priorities[$argv[0]];
			}

			$workinfo      = ['argc' => count($argv), 'argv' => $argv];
			$workinfo_json = json_encode($workinfo);
			$uuid          = self::getUuid($workinfo_json);

			$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
				dbesc($uuid)
			);
			if ($r) {
				logger("Release: Duplicate task - do not insert.", LOGGER_DEBUG);
				logger(print_r($workinfo, true));
				return;
			}

			self::qstart();
			$r = q("INSERT INTO workerq (workerq_priority, workerq_data, workerq_uuid, workerq_cmd) VALUES (%d, '%s', '%s', '%s')",
				intval($priority),
				$workinfo_json,
				dbesc($uuid),
				dbesc($argv[0])
			);
			if (!$r) {
				self::qrollback();
				logger("Insert failed: " . $workinfo_json, LOGGER_DEBUG);
				return;
			}
			self::qcommit();
			logger('INSERTED: ' . $workinfo_json, LOGGER_DEBUG);
		}

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

		self::qstart();

		// skip locked is preferred but is not supported by mariadb < 10.6 which is still used a lot - hence make it optional
		$sql_quirks = ((get_config('system', 'db_skip_locked_supported')) ? 'SKIP LOCKED' : 'NOWAIT');

		$r = q("SELECT workerq_id FROM workerq WHERE workerq_reservationid IS NOT NULL AND workerq_processtimeout < %s FOR UPDATE $sql_quirks",
			db_utcnow()
		);

		if ($r) {
			// TODO: some long running services store their pid in config.procid.daemon
			// we could possibly check if a pid exist and check if the process is still alive
			// prior to reseting workerq_reservationid

			$ids = ids_to_querystr($r, 'workerq_id');
			$u = dbq("update workerq set workerq_reservationid = null where workerq_id in ($ids)");
		}

		self::qcommit();

		//q("update workerq set workerq_reservationid = null where workerq_reservationid is not null and workerq_processtimeout < %s",
			//db_utcnow()
		//);

		//usleep(self::$workersleep);

		$workers = dbq("select count(*) as total from workerq where workerq_reservationid is not null");
		logger("WORKERCOUNT: " . $workers[0]['total'], LOGGER_DEBUG);

		return intval($workers[0]['total']);
	}

	public static function GetWorkerID() {
		if (self::$queueworker) {
			return self::$queueworker;
		}

		$wid = uniqid('', true);

		//usleep(mt_rand(300000, 1000000)); //Sleep .3 - 1 seconds before creating a new worker.

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

		self::qstart();

		// skip locked is preferred but is not supported by mariadb < 10.6 which is still used a lot - hence make it optional
		$sql_quirks = ((get_config('system', 'db_skip_locked_supported')) ? 'SKIP LOCKED' : 'NOWAIT');

		$work = dbq("SELECT workerq_id, workerq_cmd FROM workerq WHERE workerq_reservationid IS NULL ORDER BY workerq_priority DESC, workerq_id ASC LIMIT 1 FOR UPDATE $sql_quirks");

		if (!$work) {
			self::qrollback();
			return false;
		}

		$id = $work[0]['workerq_id'];
		$cmd = $work[0]['workerq_cmd'];
		$age = self::$workermaxage;

		if (in_array($cmd, self::$long_running_cmd)) {
			$age = 3600; // 1h TODO: make this configurable
		}

		$work = q("UPDATE workerq SET workerq_reservationid = '%s', workerq_processtimeout = %s + INTERVAL %s WHERE workerq_id = %d",
			self::$queueworker,
			db_utcnow(),
			db_quoteinterval($age . " SECOND"),
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
		$sleep = intval(get_config('queueworker', 'queue_worker_sleep', 100));
		$auto_queue_worker_sleep = get_config('queueworker', 'auto_queue_worker_sleep', 0);

		if (!self::GetWorkerID()) {
			if ($auto_queue_worker_sleep) {
				set_config('queueworker', 'queue_worker_sleep', $sleep + 100);
			}

			logger('Unable to get worker ID. Exiting.', LOGGER_DEBUG);
			killme();
		}

		if ($auto_queue_worker_sleep && $sleep > 100) {
			$next_sleep = $sleep - 100;
			set_config('queueworker', 'queue_worker_sleep', (($next_sleep < 100) ? 100 : $next_sleep));
		}

		$jobs               = 0;
		$workid             = self::getWorkId();
		$load_average_sleep = false;
		self::$workersleep  = $sleep;
		self::$workersleep  = ((intval(self::$workersleep) > 100) ? intval(self::$workersleep) : 100);

		if (function_exists('sys_getloadavg') && get_config('queueworker', 'load_average_sleep')) {
			// very experimental!
			$load_average_sleep = true;
		}

		while ($workid) {

			if ($load_average_sleep) {
				$load_average      = sys_getloadavg();
				self::$workersleep = intval($load_average[0]) * 10000;

				if (!self::$workersleep) {
					self::$workersleep = 100;
				}
			}

			logger('queue_worker_sleep: ' . self::$workersleep, LOGGER_DEBUG);

			usleep(self::$workersleep);

			$workitem = dbq("SELECT * FROM workerq WHERE workerq_id = $workid");

			if ($workitem) {
				// At least SOME work to do.... in case there's more, let's ramp up workers.
				$workers = self::GetWorkerCount();

				if ($workers < self::$maxworkers) {
					logger($workers . '/' . self::$maxworkers . ' workers active', LOGGER_DEBUG);
					$phpbin = get_config('system', 'phpbin', 'php');
					proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker']);
				}

				$jobs++;

				logger("Workinfo: " . $workitem[0]['workerq_data'], LOGGER_DEBUG);

				$workinfo = json_decode($workitem[0]['workerq_data'], true);
				$argv     = $workinfo['argv'];

				$cls  = '\\Zotlabs\\Daemon\\' . $argv[0];
				$argv = flatten_array_recursive($argv);
				$argc = count($argv);
				$rnd = random_string();

				logger('PROCESSING: ' . $rnd . ' ' . print_r($argv[0], true));

				$cls::run($argc, $argv);

				logger('COMPLETED: ' . $rnd);

				// @FIXME: Right now we assume that if we get a return, everything is OK.
				// At some point we may want to test whether the run returns true/false
				// and requeue the work to be tried again if needed.  But we probably want
				// to implement some sort of "retry interval" first.

				dbq("delete from workerq where workerq_id = $workid");
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

				//Give the server .3 seconds to catch its breath between tasks.
				//This will hopefully keep it from crashing to it's knees entirely
				//if the last task ended up initiating other parallel processes
				//(eg. polling remotes)
				usleep(300000);
			}

			//Make sure nothing new came in
			$work = q("select * from workerq");
		}
	}

	/**
	 * @brief Generate a name-based v5 UUID with custom namespace
	 *
	 * @param string $data
	 * @return string $uuid
	 */
	private static function getUuid(string $data) {
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
