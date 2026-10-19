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

		// Keeps the active tab shareable/bookmarkable via its own URL
		// (MVP feedback item this closes) — replaceState rather than
		// pushState so switching tabs doesn't fill up browser history.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + target );
		}
	}

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

	// Restore whichever tab the URL names on load, so a shared/bookmarked
	// "#research" link (etc.) opens straight into that tab instead of
	// always landing on Overview.
	var initialTarget = window.location.hash ? window.location.hash.slice( 1 ) : '';
	if ( initialTarget ) {
		var initialTab = document.querySelector( '.aw-profile-tab[data-aw-profile-tab="' + initialTarget + '"]' );
		if ( initialTab ) {
			activateTab( initialTab );
		}
	}
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

		// "Report an issue" on the AI Summary (Darian & Andy §11 / BE ticket
		// §29 / FE ticket §17 "Report Issue interaction") — reveals the
		// report form in place of the trigger; see the submit handler below
		// for what actually sends it.
		var reportTrigger = e.target.closest( '[data-aw-report-issue-trigger]' );
		if ( reportTrigger ) {
			var panel = reportTrigger.closest( '.aw-ai-summary-panel' );
			var form = panel && panel.querySelector( '[data-aw-report-form]' );
			if ( form ) {
				form.hidden = false;
				reportTrigger.hidden = true;
				var textarea = form.querySelector( '[data-aw-report-message]' );
				if ( textarea ) {
					textarea.focus();
				}
			}
			return;
		}

		var reportCancel = e.target.closest( '[data-aw-report-cancel]' );
		if ( reportCancel ) {
			var cancelForm = reportCancel.closest( '[data-aw-report-form]' );
			if ( cancelForm ) {
				cancelForm.hidden = true;
				cancelForm.reset();
				var cancelPanel = cancelForm.closest( '.aw-ai-summary-panel' );
				var cancelTrigger = cancelPanel && cancelPanel.querySelector( '[data-aw-report-issue-trigger]' );
				if ( cancelTrigger ) {
					cancelTrigger.hidden = false;
				}
			}
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-aw-report-form]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();

		var panel = form.closest( '.aw-ai-summary-panel' );
		var slug = panel ? panel.getAttribute( 'data-aw-report-slug' ) : null;
		var textarea = form.querySelector( '[data-aw-report-message]' );
		var honeypot = form.querySelector( '[data-aw-report-hp]' );
		var statusEl = form.querySelector( '[data-aw-report-status]' );
		var submitBtn = form.querySelector( '[data-aw-report-submit]' );
		var message = textarea ? textarea.value.trim() : '';

		if ( honeypot && honeypot.value ) {
			return; // A bot filled every field, including the hidden one — drop silently.
		}

		if ( ! slug || ! cfg.restUrl || message.length < 10 ) {
			if ( statusEl ) {
				statusEl.textContent = 'Please add a few more details before submitting.';
				statusEl.classList.add( 'is-error' );
			}
			return;
		}

		if ( submitBtn ) {
			submitBtn.disabled = true;
			submitBtn.textContent = 'Submitting...';
		}
		if ( statusEl ) {
			statusEl.textContent = '';
			statusEl.classList.remove( 'is-error' );
		}

		fetch( cfg.restUrl + '/projects/' + encodeURIComponent( slug ) + '/report-issue', {
			method: 'POST',
			headers: Object.assign( { 'Content-Type': 'application/json' }, cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {} ),
			body: JSON.stringify( { message: message } )
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Could not submit report.' );
				}
				return response.json();
			} )
			.then( function () {
				form.hidden = true;
				form.reset();
				var disclaimer = panel && panel.querySelector( '.aw-ai-disclaimer' );
				if ( disclaimer ) {
					disclaimer.insertAdjacentHTML( 'afterend', '<p class="aw-report-success">Thanks — this has been sent for editorial review.</p>' );
				}
			} )
			.catch( function () {
				if ( statusEl ) {
					statusEl.textContent = 'Something went wrong — please try again later.';
					statusEl.classList.add( 'is-error' );
				}
			} )
			.finally( function () {
				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.textContent = 'Submit report';
				}
			} );
	} );
} )();
