/**
 * Client-side JS for the Project Profile page: tab switching, and
 * AJAX-loading/filtering the AlphaWire Coverage and Research panels
 * against the plugin's own REST endpoints (see class-rest-api.php's
 * /projects/{slug}/coverage), using the URL/nonce localized as
 * window.AlphaWireProjects (see class-templates.php::enqueue_assets()).
 */
( function () {
	'use strict';

	function activateTab( tab ) {
		var tabs = document.querySelectorAll( '[data-aw-profile-tab]' );
		var panels = document.querySelectorAll( '[data-aw-profile-panel]' );
		var target = tab.getAttribute( 'data-aw-profile-tab' );

		tabs.forEach( function ( item ) {
			var active = item === tab;
			item.classList.toggle( 'is-active', active );
			item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			item.tabIndex = active ? 0 : -1;
		} );

		panels.forEach( function ( panel ) {
			var active = panel.getAttribute( 'data-aw-profile-panel' ) === target;
			panel.classList.toggle( 'is-active', active );
			panel.hidden = ! active;
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest( '[data-aw-profile-tab]' );
		if ( tab ) {
			activateTab( tab );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var tab = e.target.closest( '[data-aw-profile-tab]' );
		if ( ! tab || ( 'ArrowRight' !== e.key && 'ArrowLeft' !== e.key ) ) {
			return;
		}
		e.preventDefault();
		var tabs = Array.prototype.slice.call( document.querySelectorAll( '[data-aw-profile-tab]' ) );
		var index = tabs.indexOf( tab );
		var next = 'ArrowRight' === e.key ? ( index + 1 ) % tabs.length : ( index - 1 + tabs.length ) % tabs.length;
		tabs[ next ].focus();
		activateTab( tabs[ next ] );
	} );
} )();

( function () {
	'use strict';

	var cfg = window.AlphaWireProjects || {};
	var pageSize = 6;

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value || '';
		return div.innerHTML;
	}

	function renderCoverageCard( item ) {
		var image = item.image
			? '<img class="aw-cov-thumb" src="' + escapeHtml( item.image ) + '" alt="" />'
			: '<span class="aw-cov-thumb" aria-hidden="true"></span>';
		var duration = item.readTime
			? ' · ' + item.readTime + ( 'Podcast' === item.type ? ' min listen' : ' min read' )
			: '';
		var date = item.date ? new Date( item.date ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } ) : '';

		return '<a class="aw-panel-hover aw-coverage-item" data-aw-coverage-type="' + escapeHtml( item.type.toLowerCase().replace( /[^a-z0-9]+/g, '-' ) ) + '" href="' + escapeHtml( item.url ) + '">' +
			image +
			'<span class="aw-cov-content">' +
			'<span class="aw-cov-type">' + escapeHtml( item.type ) + '</span>' +
			'<span class="aw-cov-title">' + escapeHtml( item.title ) + '</span>' +
			( item.excerpt ? '<span class="aw-cov-excerpt">' + escapeHtml( item.excerpt.replace( /<[^>]*>/g, '' ) ) + '</span>' : '' ) +
			'<span class="aw-cov-date">' + date + duration + '</span>' +
			'</span></a>';
	}

	function loadCoverage( section, type, page, append ) {
		var slug = section.getAttribute( 'data-aw-coverage-slug' );
		var scope = section.getAttribute( 'data-aw-coverage-scope' ) || 'coverage';
		var grid = section.querySelector( '.aw-grid' );
		var button = section.querySelector( '[data-aw-coverage-load-more]' );
		if ( ! slug || ! grid || ! cfg.restUrl ) {
			return;
		}

		if ( button ) {
			button.disabled = true;
			button.classList.add( 'is-loading' );
			button.textContent = 'Loading...';
		}

		var url = cfg.restUrl + '/projects/' + encodeURIComponent( slug ) + '/coverage?scope=' + encodeURIComponent( scope ) + '&type=' + encodeURIComponent( type ) + '&page=' + page + '&per_page=' + pageSize;
		fetch( url, { headers: cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {} } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Could not load coverage.' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				var html = ( data.items || [] ).map( renderCoverageCard ).join( '' );
				if ( append ) {
					grid.insertAdjacentHTML( 'beforeend', html );
				} else {
					grid.innerHTML = html;
				}
				section.dataset.awCoveragePage = String( data.page );
				section.dataset.awCoverageType = type;
				if ( button ) {
					button.hidden = ! data.hasMore;
				}
			} )
			.catch( function () {
				if ( button ) {
					button.hidden = false;
				}
			} )
			.finally( function () {
				section.classList.remove( 'is-filter-loading' );
				section.setAttribute( 'aria-busy', 'false' );
				if ( button ) {
					button.disabled = false;
					button.classList.remove( 'is-loading' );
					button.textContent = 'Load more';
				}
			} );
	}

	function filterCoverage( filter ) {
		var section = filter.closest( '.aw-coverage-section' );
		if ( ! section ) {
			return;
		}

		var selected = filter.getAttribute( 'data-aw-coverage-filter' );
		section.querySelectorAll( '[data-aw-coverage-filter]' ).forEach( function ( item ) {
			var active = item.getAttribute( 'data-aw-coverage-filter' ) === selected;
			item.classList.toggle( 'is-active', active );
			item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		section.classList.add( 'is-filter-loading' );
		section.setAttribute( 'aria-busy', 'true' );
		loadCoverage( section, selected, 1, false );
	}

	document.addEventListener( 'click', function ( e ) {
		var filter = e.target.closest( '[data-aw-coverage-filter]' );
		if ( filter ) {
			filterCoverage( filter );
			return;
		}

		var loadMore = e.target.closest( '[data-aw-coverage-load-more]' );
		if ( loadMore ) {
			var section = loadMore.closest( '.aw-coverage-section' );
			var page = parseInt( section.dataset.awCoveragePage || '1', 10 ) + 1;
			loadCoverage( section, section.dataset.awCoverageType || 'all', page, true );
		}
	} );
} )();
