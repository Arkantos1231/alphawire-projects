/**
 * Save-to-collection star + the Collections modal — the only client-side
 * JS in this plugin (everything else is deliberately server-rendered, see
 * class-templates.php). No-ops entirely for a logged-out visitor: their
 * star is a plain <a> to the login page, not a button this file touches.
 *
 * Talks to the REST endpoints in includes/class-collections.php using the
 * nonce/URL localized as window.AlphaWireProjects (see
 * class-templates.php::enqueue_assets()).
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof window.AlphaWireProjects ) {
		return;
	}

	var cfg = window.AlphaWireProjects;
	var state = {
		collections: null, // null until first fetched
		currentProjectId: null, // the Project the open modal is acting on, or null
	};

	function api( path, options ) {
		options = options || {};
		options.headers = Object.assign(
			{ 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			options.headers || {}
		);
		return fetch( cfg.restUrl + path, options ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || 'Something went wrong.' );
					throw err;
				}
				return data;
			} );
		} );
	}

	function modalEl() {
		return document.getElementById( 'aw-collection-modal' );
	}

	function showError( message ) {
		var modal = modalEl();
		if ( ! modal ) {
			return;
		}
		var err = modal.querySelector( '[data-aw-modal-error]' );
		if ( err ) {
			err.textContent = message;
			err.hidden = false;
		}
	}

	function clearError() {
		var modal = modalEl();
		if ( ! modal ) {
			return;
		}
		var err = modal.querySelector( '[data-aw-modal-error]' );
		if ( err ) {
			err.hidden = true;
			err.textContent = '';
		}
	}

	function openModal( projectId ) {
		var modal = modalEl();
		if ( ! modal ) {
			return;
		}
		state.currentProjectId = projectId || null;
		clearError();
		modal.hidden = false;
		document.body.style.overflow = 'hidden';
		refreshCollections();
	}

	function closeModal() {
		var modal = modalEl();
		if ( ! modal ) {
			return;
		}
		modal.hidden = true;
		document.body.style.overflow = '';
		state.currentProjectId = null;
	}

	function refreshCollections() {
		var list = modalEl() && modalEl().querySelector( '[data-aw-collection-list]' );
		if ( list ) {
			list.innerHTML = '<p class="aw-muted">Loading your collections…</p>';
		}
		api( '/collections' )
			.then( function ( data ) {
				state.collections = data;
				renderList();
			} )
			.catch( function ( e ) {
				showError( e.message );
			} );
	}

	function renderList() {
		var list = modalEl() && modalEl().querySelector( '[data-aw-collection-list]' );
		if ( ! list ) {
			return;
		}
		if ( ! state.collections || 0 === state.collections.length ) {
			list.innerHTML = '<p class="aw-muted">No collections yet — create your first one below.</p>';
			return;
		}

		var pid = state.currentProjectId;
		var html = state.collections
			.map( function ( c ) {
				var count = c.projectIds.length;
				if ( null === pid ) {
					return (
						'<div class="aw-collection-row"><span>' +
						escapeHtml( c.name ) +
						'</span><span class="aw-muted">' +
						count +
						'</span></div>'
					);
				}
				var checked = c.projectIds.indexOf( pid ) !== -1 ? ' checked' : '';
				return (
					'<label class="aw-collection-row"><input type="checkbox" data-aw-toggle-collection="' +
					c.id +
					'"' +
					checked +
					' /> <span>' +
					escapeHtml( c.name ) +
					'</span><span class="aw-muted">' +
					count +
					'</span></label>'
				);
			} )
			.join( '' );

		list.innerHTML = html;
	}

	function escapeHtml( s ) {
		var div = document.createElement( 'div' );
		div.textContent = s;
		return div.innerHTML;
	}

	function refreshStarButtons( projectId, isSaved ) {
		// The star is a single inline SVG (see aw_projects_star_icon() in
		// template-functions.php) whose fill is entirely CSS-driven off the
		// is-saved class — nothing here needs to touch its markup.
		var buttons = document.querySelectorAll( '[data-aw-save-project="' + projectId + '"]' );
		buttons.forEach( function ( btn ) {
			btn.classList.toggle( 'is-saved', isSaved );
			btn.setAttribute( 'aria-pressed', isSaved ? 'true' : 'false' );
		} );
	}

	function toggleCollection( collectionId, checked ) {
		var pid = state.currentProjectId;
		if ( null === pid ) {
			return;
		}
		var path = '/collections/' + collectionId + '/projects';
		var req = checked
			? api( path, { method: 'POST', body: JSON.stringify( { project_id: pid } ) } )
			: api( path + '/' + pid, { method: 'DELETE' } );

		req
			.then( function ( updated ) {
				state.collections = state.collections.map( function ( c ) {
					return c.id === updated.id ? updated : c;
				} );
				var savedAnywhere = state.collections.some( function ( c ) {
					return c.projectIds.indexOf( pid ) !== -1;
				} );
				refreshStarButtons( pid, savedAnywhere );
			} )
			.catch( function ( e ) {
				showError( e.message );
				renderList(); // revert the checkbox to its last known-good state
			} );
	}

	function createCollection( name ) {
		clearError();
		return api( '/collections', { method: 'POST', body: JSON.stringify( { name: name } ) } )
			.then( function ( created ) {
				state.collections = ( state.collections || [] ).concat( [ created ] );
				if ( null !== state.currentProjectId ) {
					return toggleCollection( created.id, true );
				}
				// Created from a context with no Project attached (sidebar
				// "+ New collection", or the My Collections toolbar) — the
				// simplest correct thing is to reload so the server-
				// rendered list on that page picks it up.
				window.location.reload();
			} )
			.catch( function ( e ) {
				showError( e.message );
			} );
	}

	function renameCollection( id, name ) {
		return api( '/collections/' + id, { method: 'POST', body: JSON.stringify( { name: name } ) } )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( e ) {
				window.alert( e.message );
			} );
	}

	function deleteCollection( id ) {
		return api( '/collections/' + id, { method: 'DELETE' } )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( e ) {
				window.alert( e.message );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var save = e.target.closest( '[data-aw-save-project]' );
		if ( save ) {
			e.preventDefault();
			openModal( parseInt( save.getAttribute( 'data-aw-save-project' ), 10 ) );
			return;
		}

		var newCollection = e.target.closest( '[data-aw-new-collection]' );
		if ( newCollection ) {
			e.preventDefault();
			openModal( null );
			return;
		}

		if ( e.target.closest( '[data-aw-modal-close]' ) ) {
			e.preventDefault();
			closeModal();
			return;
		}

		var toggle = e.target.closest( '[data-aw-toggle-collection]' );
		if ( toggle && 'checkbox' === toggle.type ) {
			// handled by the change listener below; nothing to do on click
			return;
		}

		var rename = e.target.closest( '[data-aw-rename-collection]' );
		if ( rename ) {
			var newName = window.prompt( 'Rename collection', rename.getAttribute( 'data-name' ) || '' );
			if ( newName && newName.trim() ) {
				renameCollection( rename.getAttribute( 'data-id' ), newName.trim() );
			}
			return;
		}

		var del = e.target.closest( '[data-aw-delete-collection]' );
		if ( del ) {
			if ( window.confirm( 'Delete this collection? This cannot be undone.' ) ) {
				deleteCollection( del.getAttribute( 'data-id' ) );
			}
			return;
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var toggle = e.target.closest( '[data-aw-toggle-collection]' );
		if ( toggle ) {
			toggleCollection( toggle.getAttribute( 'data-aw-toggle-collection' ), toggle.checked );
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '[data-aw-new-collection-form]' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();
		var input = form.querySelector( '[data-aw-new-collection-name]' );
		var name = input && input.value.trim();
		if ( name ) {
			createCollection( name ).then( function () {
				if ( input ) {
					input.value = '';
				}
			} );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			var modal = modalEl();
			if ( modal && ! modal.hidden ) {
				closeModal();
			}
		}
	} );
} )();
