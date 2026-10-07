/**
 * Client-side JS for the Project Profile page: tab switching, and
 * AJAX-loading/filtering the AlphaWire Coverage and Research panels
 * against the plugin's own REST endpoints (see class-rest-api.php's
 * /projects/{slug}/coverage), using the URL/nonce localized as
 * window.AlphaWireProjects (see class-templates.php::enqueue_assets()).
 */
( function () {
	'use strict';

	// Staging feedback #8: each tab gets its own shareable address
	// (/projects/solana/#timeline). A hash, not a path, so it needs no
	// rewrite rules and the server still renders one page.
	var TAB_HASH_PREFIX = '#';

	function tabFromHash() {
		var name = ( window.location.hash || '' ).replace( TAB_HASH_PREFIX, '' );
		if ( ! name || ! /^[a-z-]+$/.test( name ) ) {
			return null;
		}
		return document.querySelector( '.aw-profile-tab[data-aw-profile-tab="' + name + '"]' );
	}

	function updateHash( tab ) {
		var name = tab.getAttribute( 'data-aw-profile-tab' );
		var hash = 'overview' === name ? '' : TAB_HASH_PREFIX + name;
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + window.location.search + hash );
		}
	}

	function activateTab( tab, skipHash ) {
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

		if ( ! skipHash ) {
			updateHash( tab );
		}
	}

	function activateFromHash() {
		var tab = tabFromHash();
		if ( tab ) {
			activateTab( tab, true );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', activateFromHash );
	} else {
		activateFromHash();
	}
	window.addEventListener( 'hashchange', activateFromHash );

	document.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest( '[data-aw-profile-tab]' );
		if ( tab ) {
			activateTab( tab );
			return;
		}

		// "View full timeline" (and similar in-page shortcuts) on the
		// Overview tab: not a real tab button, so activate the matching
		// tab through the real one — keeps the tab bar's active state and
		// the shown panel in sync instead of only swapping the panel.
		var goto = e.target.closest( '[data-aw-profile-goto]' );
		if ( goto ) {
			var targetId = goto.getAttribute( 'data-aw-profile-goto' );
			var realTab = document.querySelector( '.aw-profile-tab[data-aw-profile-tab="' + targetId + '"]' );
			if ( realTab ) {
				e.preventDefault();
				activateTab( realTab );
				realTab.scrollIntoView( { behavior: 'smooth', block: 'nearest', inline: 'nearest' } );
			}
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
		// A filter change starts back at page 1, so mobile should cap it
		// at 4 again too.
		section.classList.remove( 'aw-coverage-expanded' );
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
			// Lifts the mobile 4-at-a-time cap (see projects.css) so the
			// items it's about to fetch aren't hidden along with them.
			section.classList.add( 'aw-coverage-expanded' );
			var page = parseInt( section.dataset.awCoveragePage || '1', 10 ) + 1;
			loadCoverage( section, section.dataset.awCoverageType || 'all', page, true );
		}
	} );
} )();

/**
 * "Report an issue" on the AI Project Summary (staging feedback #5).
 * Posts to /projects/{slug}/report-issue — see
 * AlphaWire_Projects_REST::report_issue().
 */
( function () {
	'use strict';

	var cfg = window.AlphaWireProjects || {};

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '.aw-report-form' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();

		var wrapper = form.closest( '[data-aw-report-issue]' );
		var status = form.querySelector( '.aw-report-status' );
		var button = form.querySelector( 'button[type="submit"]' );
		var message = ( form.elements.message.value || '' ).trim();

		if ( message.length < 5 ) {
			status.textContent = 'Please tell us what looks wrong.';
			return;
		}
		if ( ! cfg.restUrl || ! wrapper ) {
			status.textContent = 'Reporting is unavailable right now.';
			return;
		}

		button.disabled = true;
		status.textContent = 'Sending…';

		var headers = { 'Content-Type': 'application/json' };
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}

		fetch( cfg.restUrl + '/projects/' + encodeURIComponent( wrapper.getAttribute( 'data-aw-report-issue' ) ) + '/report-issue', {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify( { message: message, website: form.elements.website ? form.elements.website.value : '' } )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || 'Could not send your report.' );
					}
					return data;
				} );
			} )
			.then( function () {
				form.reset();
				status.textContent = 'Thanks — an editor will review this summary.';
			} )
			.catch( function ( err ) {
				status.textContent = err.message || 'Could not send your report.';
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
} )();
