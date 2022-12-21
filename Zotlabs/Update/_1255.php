<?php

namespace Zotlabs\Update;

class _1255 {

	function run() {

		dbq("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r = q("ALTER TABLE workerq add workerq_cmd text NOT NULL DEFAULT ''");
		}

		if(ACTIVE_DBTYPE == DBTYPE_MYSQL) {
			$r = q("ALTER TABLE workerq add workerq_cmd varchar(191) NOT NULL DEFAULT ''");
		}

		if($r) {
			dbq("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
