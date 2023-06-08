<?php /** @file */

namespace Zotlabs\Daemon;

use Zotlabs\Lib\Queue as LibQueue;

class Queue {

	static public function run($argc, $argv) {
		$queue_id = ($argc > 1) ? $argv[1] : '';

		logger('queue: start');

		// delete all queue items more than 3 days old
		// but first mark these sites dead if we haven't heard from them in a month

		$oldqItems = q("select outq_posturl, outq_hash from outq where outq_created < %s - INTERVAL %s",
			db_utcnow(),
			db_quoteinterval('3 DAY')
		);

		if ($oldqItems) {
			foreach ($oldqItems as $qItem) {
				$h = parse_url($qItem['outq_posturl']);
				$site_url = $h['scheme'] . '://' . $h['host'] . ((!empty($h['port'])) ? ':' . $h['port'] : '');
				q("update site set site_dead = 1 where site_dead = 0 and site_url = '%s' and site_update < %s - INTERVAL %s",
					dbesc($site_url),
					db_utcnow(),
					db_quoteinterval('1 MONTH')
				);
			}

			$old_hashes = ids_to_querystr($oldqItems, 'outq_hash', true);

			logger('Removing ' . count($oldqItems) . ' old queue entries');
			dbq("DELETE FROM outq WHERE outq_hash IN ($old_hashes)");

		}

		$deliveries = [];

		if ($queue_id) {
			$qItems = q("SELECT * FROM outq WHERE outq_hash = '%s' LIMIT 1",
				dbesc($queue_id)
			);
			logger('queue deliver: ' . $qItems[0]['outq_hash'] . ' to ' . $qItems[0]['outq_posturl'], LOGGER_DEBUG);
			LibQueue::deliver($qItems[0]);
		}
		else {
			$qItems = q("SELECT outq_hash FROM outq WHERE outq_scheduled < %s ",
				db_utcnow()
			);

			if ($qItems) {
				foreach ($qItems as $qItem) {
					$deliveries[] = $qItem['outq_hash'];
				}
				shuffle($deliveries);
				hz_syslog(print_r($deliveries, true));

				do_delivery($deliveries, true);
			}
		 }
	}

}
