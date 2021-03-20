$(function() {

	window.Pilot.Common = {

		loadAccountInfo: function() {

			var usernames = [];

			$('[data-account-info]').each(function(e) {
				usernames.push( $(this).data('account-info') );
		    });

		    var unique_usernames = $.unique(usernames);

			$.each(unique_usernames, function(i, username) {

				var $that = $('[data-account-info="' + username + '"]');

				$.get('https://www.instagram.com/' + username + '/', function(response) {

		            var $json = JSON.parse(response.split("window._sharedData = ")[1].split(";<\/script>")[0]);
		            var $user = $json.entry_data.ProfilePage[0].graphql.user;

		            var followed_by_count = $user.edge_followed_by.count
		            var following_count = $user.edge_follow.count
		            var posts_count = $user.edge_owner_to_timeline_media.count
		            var avatar = response.match(/<meta property="og:image" content="(.*?)" \/>/)[1];

		        	$that.find('[data-avatar]').css('background-image', 'url(' + avatar + ')');
		        	$that.find('[data-followers]').text(followed_by_count);
		        	$that.find('[data-following]').text(following_count);
		        	$that.find('[data-posts]').text(posts_count);

		        });

		    });

		}
	}

	$('[data-toggle="card-collapse"]').on('click', function(e) {
		let $card = $(this).closest('div.card');
		$card.toggleClass('card-collapsed');
		e.preventDefault();
		return false;
	});

	$('input.dm-date-time-picker[type=text]').flatpickr({
        locale: document.documentElement.lang,
        enableTime: true,
        allowInput: true,
        time_24hr: true,
        enableSeconds: true,
        altInput: true,
        altFormat: "H:i - F j, Y",
        dateFormat: "Y-m-d H:i:S"
    });

    if($('.dm-show-more').length) {
	    $('.dm-show-more').showMore({
			minheight: 57,
			buttontxtmore: 'show more..',
			buttontxtless: 'show less',
			buttoncss: 'text-muted small',
			animationspeed: 300
		});
	}

    if($('.dm-viewer-container').length) {
		new Viewer(document.querySelector('.dm-viewer-container'), {
			url: 'data-original',
			fullscreen: false,
			loop: false,
			movable: false,
			navbar: false,
			rotatable: false,
			slideOnTouch: false,
			title: false,
			toggleOnDblclick: false,
			toolbar: false,
			tooltip: false,
			zoomable: false,
			zoomOnTouch: false,
			zoomOnWheel: false
		});
    }

	window.Pilot.Common.loadAccountInfo();

});