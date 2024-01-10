<?php
namespace Zotlabs\Module;

use Zotlabs\Lib\IConfig;
use Zotlabs\Lib\Enotify;
use Zotlabs\Web\Controller;
use Zotlabs\Daemon\Master;
use Zotlabs\Lib\Activity as ZlibActivity;
use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Web\HTTPSig;
use Zotlabs\Lib\Libzot;
use Zotlabs\Lib\ThreadListener;
use App;


class Activity extends Controller {

	function init() {

		if (Libzot::is_zot_request()) {

			$item_id = argv(1);
			if (! $item_id)
				http_status_exit(404, 'Not found');

			$portable_id = EMPTY_STR;

			$item_normal_extra = sprintf(" and not verb in ('%s', '%s') ",
				dbesc(ACTIVITY_FOLLOW),
				dbesc(ACTIVITY_UNFOLLOW)
			);

			$item_normal = " and item.item_hidden = 0 and item.item_type = 0 and item.item_unpublished = 0 and item.item_delayed = 0 and item.item_blocked = 0 $item_normal_extra ";

			$i = null;

			// do we have the item (at all)?

			$r = q("select * from item where mid = '%s' $item_normal limit 1",
				dbesc(z_root() . '/activity/' . $item_id)
			);

			if (! $r) {
				http_status_exit(404,'Not found');
			}

			// process an authenticated fetch

			$sigdata = HTTPSig::verify(EMPTY_STR);
			if($sigdata['portable_id'] && $sigdata['header_valid']) {
				$portable_id = $sigdata['portable_id'];
				observer_auth($portable_id);

				// first see if we have a copy of this item's parent owned by the current signer
				// include xchans for all zot-like networks - these will have the same guid and public key

				$x = q("select * from xchan where xchan_hash = '%s'",
					dbesc($sigdata['portable_id'])
				);

				if ($x) {
					$xchans = q("select xchan_hash from xchan where xchan_hash = '%s' OR ( xchan_guid = '%s' AND xchan_pubkey = '%s' ) ",
						dbesc($sigdata['portable_id']),
						dbesc($x[0]['xchan_guid']),
						dbesc($x[0]['xchan_pubkey'])
					);

					if ($xchans) {
						$hashes = ids_to_querystr($xchans,'xchan_hash',true);
						$i = q("select id as item_id from item where mid = '%s' $item_normal and owner_xchan in ( " . protect_sprintf($hashes) . " ) limit 1",
							dbesc($r[0]['parent_mid'])
						);
					}
				}
			}

			// if we don't have a parent id belonging to the signer see if we can obtain one as a visitor that we have permission to access
			// with a bias towards those items owned by channels on this site (item_wall = 1)

			$sql_extra = item_permissions_sql(0);

			if (! $i) {
				$i = q("select id as item_id from item where mid = '%s' $item_normal $sql_extra order by item_wall desc limit 1",
					dbesc($r[0]['parent_mid'])
				);
			}

			if(! $i) {
				http_status_exit(403,'Forbidden');
			}

			$parents_str = ids_to_querystr($i,'item_id');

			$items = q("SELECT item.*, item.id AS item_id FROM item WHERE item.parent IN ( %s ) $item_normal ",
				dbesc($parents_str)
			);

			if(! $items) {
				http_status_exit(404, 'Not found');
			}

			xchan_query($items,true);
			$items = fetch_post_tags($items,true);

			$observer = App::get_observer();
			$parent = $items[0];
			$recips = (($parent['owner']['xchan_network'] === 'activitypub') ? get_iconfig($parent['id'],'activitypub','recips', []) : []);
			$to = (($recips && array_key_exists('to',$recips) && is_array($recips['to'])) ? $recips['to'] : null);
			$nitems = [];
			foreach($items as $i) {

				$mids = [];

				if(intval($i['item_private'])) {
					if(! $observer) {
						continue;
					}
					// ignore private reshare, possibly from hubzilla
					if($i['verb'] === 'Announce') {
						if(! in_array($i['thr_parent'],$mids)) {
							$mids[] = $i['thr_parent'];
						}
						continue;
					}
					// also ignore any children of the private reshares
					if(in_array($i['thr_parent'],$mids)) {
						continue;
					}

					if((! $to) || (! in_array($observer['xchan_url'],$to))) {
						continue;
					}

				}
				$nitems[] = $i;
			}

			if(! $nitems)
				http_status_exit(404, 'Not found');

			$chan = channelx_by_n($nitems[0]['uid']);

			if(! $chan)
				http_status_exit(404, 'Not found');

			if(! perm_is_allowed($chan['channel_id'],get_observer_hash(),'view_stream'))
				http_status_exit(403, 'Forbidden');

			$i = ZlibActivity::encode_item_collection($nitems,'conversation/' . $item_id,'OrderedCollection');
			if($portable_id && (! intval($items[0]['item_private']))) {
				ThreadListener::store(z_root() . '/activity/' . $item_id, $portable_id);
			}

			if(! $i)
				http_status_exit(404, 'Not found');

			as_return_and_die($i, $chan);

		}

		if(ActivityStreams::is_as_request()) {

			$item_id = argv(1);

			if (! $item_id) {
				return;
			}

			$ob_authorize = false;
			$item_uid = 0;

			$bear = ZlibActivity::token_from_request();
			if ($bear) {
				logger('bear: ' . $bear, LOGGER_DEBUG);
				$t = q("select item.uid, iconfig.v from iconfig left join item on iid = item.id where cat = 'ocap' and item.uuid = '%s'",
					dbesc($item_id)
				);
				if ($t) {
					foreach ($t as $token) {
						if ($token['v'] === $bear) {
							$ob_authorize = true;
							$item_uid = $token['uid'];
							break;
						}
					}
				}
			}

			$item_normal_extra = sprintf(" and not verb in ('%s', '%s') ",
				dbesc(ACTIVITY_FOLLOW),
				dbesc(ACTIVITY_UNFOLLOW)
			);

			$item_normal = " and item.item_hidden = 0 and item.item_type = 0 and item.item_unpublished = 0 and item.item_delayed = 0 and item.item_blocked = 0 $item_normal_extra ";

			$sigdata = HTTPSig::verify(EMPTY_STR);
			if ($sigdata['portable_id'] && $sigdata['header_valid']) {
				$portable_id = $sigdata['portable_id'];
				if (! check_channelallowed($portable_id)) {
					http_status_exit(403, 'Permission denied');
				}
				if (! check_siteallowed($sigdata['signer'])) {
					http_status_exit(403, 'Permission denied');
				}
				observer_auth($portable_id);
			}

			// if passed an owner_id of 0 to item_permissions_sql(), we force "guest access" or observer checking
			// Give ocap tokens priority

			if ($ob_authorize) {
				$sql_extra = " and item.uid = " . intval($token['uid']) . " ";
			}
			else {
				$sql_extra = item_permissions_sql(0);
			}

			$r = q("select * from item where uuid = '%s' $item_normal $sql_extra limit 1",
				dbesc($item_id)
			);

			if (! $r) {
				$r = q("select * from item where uuid = '%s' $item_normal limit 1",
					dbesc($item_id)
				);
				if($r) {
					http_status_exit(403, 'Forbidden');
				}
				http_status_exit(404, 'Not found');
			}

			xchan_query($r,true);
			$items = fetch_post_tags($r,false);

			if ($portable_id && (! intval($items[0]['item_private']))) {
				$c = q("select abook_id from abook where abook_channel = %d and abook_xchan = '%s'",
					intval($items[0]['uid']),
					dbesc($portable_id)
				);
				if (! $c) {
					ThreadListener::store(z_root() . '/activity/' . $item_id, $portable_id);
				}
			}

			$channel = channelx_by_n($items[0]['uid']);

			as_return_and_die(ZlibActivity::encode_activity($items[0]), $channel);
		}

		goaway(z_root() . '/item/' . argv(1));

	}

}
