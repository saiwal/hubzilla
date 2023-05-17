<?php

namespace Zotlabs\Update;

class _1257 {

	function run() {

		dbq("START TRANSACTION");

		$r1 = dbq("TRUNCATE TABLE updates");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r2 = dbq("ALTER TABLE updates add ud_host text NOT NULL DEFAULT ''");
		}

		if(ACTIVE_DBTYPE == DBTYPE_MYSQL) {
			$r2 = dbq("ALTER TABLE updates add ud_host varchar(191) NOT NULL DEFAULT ''");
		}

		$r3 = dbq("ALTER TABLE updates DROP COLUMN ud_guid");

		if($r1 && $r2 && $r3) {
			dbq("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
