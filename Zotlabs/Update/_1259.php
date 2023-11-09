<?php

namespace Zotlabs\Update;

class _1259 {

	function run() {

		dbq("START TRANSACTION");


		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r = true;
		}

		if(ACTIVE_DBTYPE == DBTYPE_MYSQL) {
			$r = dbq("ALTER TABLE cache MODIFY COLUMN v MEDIUMTEXT NOT NULL");
		}

		if($r) {
			dbq("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
