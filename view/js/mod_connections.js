$(document).ready(function() {
	$("#contacts-search").name_autocomplete(baseurl + '/acl', 'a', true, function(data) {
		$("#contacts-search-xchan").val(data.xid);
	});
	$(".autotime").timeago();
});

