<?php

namespace Zotlabs\Daemon;

use Zotlabs\Lib\Libzot;

class Zotconvo {

	static public function run($argc, $argv) {

		logger('Zotconvo invoked: ' . print_r($argv, true));

		if ($argc != 3) {
			return;
		}

		$channel_id = intval($argv[1]);
		$mid = $argv[2];

		$channel = channelx_by_n($channel_id);

		if (!$channel) {
			return;
		}

		Libzot::fetch_conversation($channel, $mid);

		return;

	}
}
