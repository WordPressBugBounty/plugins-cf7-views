document.addEventListener( 'DOMContentLoaded', function () {
	try {
		// Add minimal enhancements to WordPress generated elements that can't be styled server-side

		// Enhance search box
		var searchInput = document.querySelector( '.wrap.cf7-views-admin .search-box input[type="search"]' );
		if ( searchInput && !searchInput.classList.contains( 'cf7-views-input' ) ) {
			searchInput.classList.add( 'cf7-views-input' );
		}

		// Enhance bulk actions
		var bulkSelects = document.querySelectorAll( '.wrap.cf7-views-admin .bulkactions select' );
		bulkSelects.forEach( function ( select ) {
			if ( !select.classList.contains( 'cf7-views-select' ) ) {
				select.classList.add( 'cf7-views-select' );
			}
		} );

		var bulkButtons = document.querySelectorAll( '.wrap.cf7-views-admin .bulkactions input[type="submit"]' );
		bulkButtons.forEach( function ( button ) {
			if ( !button.classList.contains( 'cf7-views-button-secondary' ) ) {
				button.classList.add( 'cf7-views-button-secondary' );
			}
		} );

	} catch ( e ) {
		// Fail silently
		console.debug( 'CF7 Views Tailwind helper error:', e );
	}
} );
