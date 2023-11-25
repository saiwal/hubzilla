$(document).ready(function() {

	$('.autotime').timeago();

	if (bParam_mid) {
		src = 'hq';
		$('.channel-activities-toggle').removeClass('d-none');
	}
	else {
		$('#channel-activities').removeClass('d-none');
	}

	$(document).one('click', '.notification, .message', function(e) {
		page_load = false;
		followUpPageLoad = true;
		src = 'hq';
		$('#channel-activities').addClass('d-none');
		$('.channel-activities-toggle').removeClass('d-none');
	});

	$(document).on('click', '.channel-activities-toggle', function(e) {
		$(window).scrollTop(0);
		$('#channel-activities').toggleClass('d-none');
		$(this).toggleClass('active');
	});

	$(document).on('click', '.jot-toggle', function(e) {
		$('#jot-popup').toggle();
		$('#profile-jot-text').focus().get(0).scrollIntoView({block: 'center'});
		$(this).toggleClass('active');
	});

	$(document).on('click', '.notes-toggle', function(e) {
		$('#personal-notes').toggleClass('d-none');
		$('#note-text-html').get(0).scrollIntoView({block: 'center'});
		$(this).toggleClass('active');
	});

});
