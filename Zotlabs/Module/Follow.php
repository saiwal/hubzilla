<?php
namespace Zotlabs\Module;

use App;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Libsync;
use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Lib\Activity;
use Zotlabs\Web\HTTPSig;
use Zotlabs\Lib\Connect;
use Zotlabs\Daemon\Master;

class Follow extends Controller {

	function init() {

		if (ActivityStreams::is_as_request() && argc() == 2) {

			$abook_id = intval(argv(1));
			if(! $abook_id)
				return;

			$r = q("select * from abook left join xchan on abook_xchan = xchan_hash where abook_id = %d",
				intval($abook_id)
			);
			if (! $r) {
				return;
			}

			$chan = channelx_by_n($r[0]['abook_channel']);

			if (! $chan) {
				http_status_exit(404, 'Not found');
			}

			$actor = Activity::encode_person($chan,true,true);
			if (! $actor) {
				http_status_exit(404, 'Not found');
			}

			$obj = [
				'id'     => z_root() . '/follow/' . $r[0]['abook_id'],
				'type'   => 'Follow',
				'actor'  => $actor,
				'object' => $r[0]['xchan_url']
			];

			as_return_and_die($obj, $chan);
		}

		if (! local_channel()) {
			return;
		}

		$uid = local_channel();
		$url = notags(punify(trim($_REQUEST['url'])));
		$return_url = $_SESSION['return_url'];
		$interactive = $_REQUEST['interactive'] ?? 1;
		$channel = App::get_channel();

		$result = Connect::connect($channel, $url);

		if ($result['success'] == false) {
			if ($result['message']) {
				notice($result['message']);
			}
			if ($interactive) {
				goaway($return_url);
			}
			else {
				json_return_and_die($result);
			}
		}

		info( t('Connection added.') . EOL);

		$clone = array();
		foreach ($result['abook'] as $k => $v) {
			if (strpos($k,'abook_') === 0) {
				$clone[$k] = $v;
			}
		}
		unset($clone['abook_id']);
		unset($clone['abook_account']);
		unset($clone['abook_channel']);

		$abconfig = load_abconfig($channel['channel_id'],$clone['abook_xchan']);
		if ($abconfig) {
			$clone['abconfig'] = $abconfig;
		}
		Libsync::build_sync_packet(0, [ 'abook' => [ $clone ] ], true);

		$can_view_stream = intval(get_abconfig($channel['channel_id'], $clone['abook_xchan'], 'their_perms', 'view_stream'));

		// If we can view their stream, pull in some posts

		if (($can_view_stream) || ($result['abook']['xchan_network'] === 'rss')) {
			Master::Summon([ 'Onepoll', $result['abook']['abook_id'] ]);
		}

		if ($interactive) {
			goaway(z_root() . '/connections#' . $result['abook']['abook_id']);
		}
		else {
			json_return_and_die([ 'success' => true ]);
		}

	}

	function get() {
		if (! local_channel()) {
			return login();
		}
	}
}
