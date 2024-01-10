<?php

namespace Zotlabs\Module;

use Zotlabs\Web\Controller;
use Zotlabs\Lib\Activity;

class Apschema extends Controller {
	function init() {
		header('Content-Type: application/ld+json');
		echo json_encode(Activity::ap_context(), JSON_UNESCAPED_SLASHES);
		killme();
	}
}
