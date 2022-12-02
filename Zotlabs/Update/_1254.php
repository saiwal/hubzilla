<?php

namespace Zotlabs\Update;

class _1254 {

	function run() {

		dbq("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r1 = dbq("CREATE TABLE IF NOT EXISTS workerq (
				workerq_id bigserial NOT NULL,
				workerq_priority smallint,
				workerq_reservationid varchar(25) DEFAULT NULL,
				workerq_processtimeout timestamp NOT NULL DEFAULT '0001-01-01 00:00:00',
				workerq_data text,
				workerq_uuid UUID NOT NULL,
				PRIMARY KEY (workerq_id))"
			);

			$r2 = dbq("CREATE INDEX idx_workerq_priority ON workerq (workerq_priority)");
			$r3 = dbq("CREATE INDEX idx_workerq_reservationid ON workerq (workerq_reservationid)");
			$r4 = dbq("CREATE INDEX idx_workerq_processtimeout ON workerq (workerq_processtimeout)");
			$r5 = dbq("CREATE INDEX idx_workerq_uuid ON workerq (workerq_uuid)")

			$r = ($r1 && $r2 && $r3 && $r4 && $r5);
		}
		else {
			$r = dbq("CREATE TABLE IF NOT EXISTS workerq (
				workerq_id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
				workerq_priority smallint,
				workerq_reservationid varchar(25) DEFAULT NULL,
				workerq_processtimeout datetime NOT NULL DEFAULT '0001-01-01 00:00:00',
				workerq_data text,
				workerq_uuid char(36) NOT NULL DEFAULT ''
				KEY workerq_priority (workerq_priority),
				KEY workerq_reservationid (workerq_reservationid),
				KEY workerq_processtimeout (workerq_uuid)
				KEY workerq_uuid` (workerq_processtimeout)
				) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4"
			);
		}

		if($r) {
			dbq("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
