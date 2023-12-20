<?php
namespace Zotlabs\Module;

use App;
use Zotlabs\Daemon\Master;
use Zotlabs\Lib\Activity;
use Zotlabs\Lib\Libsync;


require_once('include/security.php');
require_once('include/bbcode.php');


class Share extends \Zotlabs\Web\Controller {

	function init() {

		if (!intval(argv(1))) {
			killme();
		}

		if (! local_channel()) {
			killme();
		}

		$observer = App::get_observer();
		$channel = App::get_channel();
		$sys_channel = get_sys_channel();

		$r = q("SELECT * from item left join xchan on author_xchan = xchan_hash WHERE id = %d  LIMIT 1",
			intval(argv(1))
		);

		if ($r[0]['uid'] === $sys_channel['channel_id']) {
			$r = [copy_of_pubitem($channel, $r[0]['mid'])];
		}

		if(! $r) {
			killme();
		}

		$item_id = $r[0]['id'];

		if ($r[0]['item_private']) {
			killme();
		}

		$sql_extra = item_permissions_sql($r[0]['uid']);

		$r = q("select * from item where id = %d $sql_extra",
			intval($item_id)
		);

		if(! $r)
			killme();

		/** @FIXME we only share bbcode */

		if($r[0]['mimetype'] !== 'text/bbcode')
			killme();

		xchan_query($r,true);

		$arr = [];

		$item = $r[0];

		$owner_uid = $r[0]['uid'];
		$owner_aid = $r[0]['aid'];

		$can_comment = false;
		if((array_key_exists('owner',$item)) && intval($item['owner']['abook_self']))
			$can_comment = perm_is_allowed($item['uid'],$observer['xchan_hash'],'post_comments');
		else
			$can_comment = can_comment_on_post($observer['xchan_hash'],$item);

		if(! $can_comment) {
			notice( t('Permission denied') . EOL);
			killme();
		}

		$r = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($item['owner_xchan'])
		);

		if($r)
			$thread_owner = $r[0];
		else
			killme();

		$r = q("select * from xchan where xchan_hash = '%s' limit 1",
			dbesc($item['author_xchan'])
		);
		if($r)
			$item_author = $r[0];
		else
			killme();


		$arr['aid'] = $owner_aid;
		$arr['uid'] = $owner_uid;

		$arr['item_origin'] = 1;
		$arr['item_wall'] = $item['item_wall'];
		$arr['uuid'] = item_message_id();
		$arr['mid'] = z_root() . '/activity/' . $arr['uuid'];
		$arr['parent_mid'] = $item['mid'];

		$mention = '@[zrl=' . $item['author']['xchan_url'] . ']' . $item['author']['xchan_name'] . '[/zrl]';
		$arr['body'] = sprintf( t('&#x1f501; Repeated %1$s\'s %2$s'), $mention, Activity::activity_obj_mapper($item['obj_type']));

		$arr['author_xchan'] = $channel['channel_hash'];
		$arr['owner_xchan']  = $item['author_xchan'];
		$arr['obj'] = Activity::encode_item($item);
		$arr['obj_type'] = $item['obj_type'];
		$arr['verb'] = ACTIVITY_SHARE;

		$post = item_store($arr);

		$post_id = $post['item_id'];

		$arr['id'] = $post_id;

		call_hooks('post_local_end', $arr);

		info( t('Post repeated') . EOL);

		$r = q("select * from item where id = %d",
			intval($post_id)
		);
		if($r) {
			xchan_query($r);
			$sync_item = fetch_post_tags($r);
			Libsync::build_sync_packet($channel['channel_id'], [ 'item' => [ encode_item($sync_item[0],true) ] ]);
		}

		Master::Summon([ 'Notifier', 'like', $post_id ]);

		killme();

	}

}
