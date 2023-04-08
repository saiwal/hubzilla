<?php

namespace Zotlabs\Module\Admin;

use App;
use Zotlabs\Web\Controller;

class Queueworker extends Controller {

	function init() {

	}

	function post() {

		check_form_security_token('form_security_token', 'queueworker');

		$maxqueueworkers = intval($_POST['queueworker_maxworkers']);
		$maxqueueworkers = ($maxqueueworkers > 3) ? $maxqueueworkers : 4;
		set_config('queueworker', 'max_queueworkers', $maxqueueworkers);

		$maxworkerage = intval($_POST['queueworker_max_age']);
		$maxworkerage = ($maxworkerage >= 120) ? $maxworkerage : 300;
		set_config('queueworker', 'queueworker_max_age', $maxworkerage);

		$queueworkersleep = intval($_POST['queue_worker_sleep']);
		$queueworkersleep = ($queueworkersleep > 100) ? $queueworkersleep : 100;
		set_config('queueworker', 'queue_worker_sleep', $queueworkersleep);

		$auto_queue_worker_sleep = intval($_POST['auto_queue_worker_sleep']);
		set_config('queueworker', 'auto_queue_worker_sleep', $auto_queue_worker_sleep);

		goaway(z_root() . '/admin/queueworker');
	}

	function get() {

		$content = "<H1>Queue Status</H1>\n";

		$r = q('select count(*) as total from workerq');

		$content .= "<H4>There are " . $r[0]['total'] . " queue items to be processed.</H4>";

		$r = dbq("select count(*) as qworkers from workerq where workerq_reservationid is not null");

		$content .= "<H4>Active workers: " . $r[0]['qworkers'] . "</H4>";

		$r = dbq("select workerq_cmd, count(*) as total from workerq where true group by workerq_cmd");

		if ($r) {
			$content .= "<H4>Work items</H4>";
			foreach($r as $rr) {
				$content .= $rr['workerq_cmd'] . ': ' . $rr['total'] . '<br>';
			}
		}

		$maxqueueworkers = get_config('queueworker', 'max_queueworkers', 4);
		$maxqueueworkers = ($maxqueueworkers > 3) ? $maxqueueworkers : 4;

		$sc = '';

		$sc .= replace_macros(get_markup_template('field_input.tpl'), [
			'$field' => [
				'queueworker_maxworkers',
				t('Max queueworker threads'),
				$maxqueueworkers,
				t('Minimum 4, default 4')
			]
		]);

		$workermaxage = get_config('queueworker', 'queueworker_max_age');
		$workermaxage = ($workermaxage >= 120) ? $workermaxage : 300;

		$sc .= replace_macros(get_markup_template('field_input.tpl'), [
			'$field' => [
				'queueworker_max_age',
				t('Assume workers dead after'),
				$workermaxage,
				t('Minimum 120, default 300 seconds')
			]
		]);

		$queueworkersleep = get_config('queueworker', 'queue_worker_sleep');
		$queueworkersleep = ($queueworkersleep > 100) ? $queueworkersleep : 100;

		$auto_queue_worker_sleep = get_config('queueworker', 'auto_queue_worker_sleep', 0);

		$sc .= replace_macros(get_markup_template('field_input.tpl'), [
			'$field' => [
				'queue_worker_sleep',
				t('Pause before starting next task'),
				$queueworkersleep,
				t('Minimum 100, default 100 microseconds'),
				'',
				(($auto_queue_worker_sleep) ? 'disabled' : '')
			]
		]);

		$sc .= replace_macros(get_markup_template('field_checkbox.tpl'), [
			'$field' => [
				'auto_queue_worker_sleep',
				t('Automatically adjust pause before starting next task'),
				$auto_queue_worker_sleep,
			]
		]);

		$tpl = get_markup_template('settings_addon.tpl');
		$content .= replace_macros($tpl, [
				'$action_url' => 'admin/queueworker',
				'$form_security_token' => get_form_security_token('queueworker'),
				'$title' => t('Queueworker Settings'),
				'$content' => $sc,
				'$baseurl' => z_root(),
				'$submit' => t('Save')
			]
		);

		return $content;

	}
}
