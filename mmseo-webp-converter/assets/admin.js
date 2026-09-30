( function () {
	'use strict';

	var cfg = window.mmseoWebp;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	function el( id ) {
		return document.getElementById( id );
	}

	function log( text ) {
		var box = el( 'mmseo-log' );
		box.textContent += text + '\n';
		box.scrollTop = box.scrollHeight;
	}

	function bar( pct ) {
		var value = Math.min( 100, Math.max( 0, pct ) );
		el( 'mmseo-bar' ).style.width = value + '%';
		el( 'mmseo-bar' ).parentNode.setAttribute( 'aria-valuenow', String( Math.round( value ) ) );
	}

	function settings() {
		return {
			quality: el( 'mmseo-quality' ).value,
			delete: el( 'mmseo-delete' ).checked ? 1 : 0,
			auto: el( 'mmseo-auto' ).checked ? 1 : 0,
			redirect: el( 'mmseo-redirect' ).checked ? 1 : 0,
			purge: el( 'mmseo-purge' ).checked ? 1 : 0
		};
	}

	async function call( phase, run, cursor, extra ) {
		var form = new FormData();
		form.append( 'action', 'mmseo_webp' );
		form.append( 'nonce', cfg.nonce );
		form.append( 'phase', phase );
		form.append( 'run', run || '' );
		form.append( 'cursor', JSON.stringify( cursor || {} ) );
		Object.keys( extra || {} ).forEach( function ( key ) {
			form.append( key, extra[ key ] );
		} );
		var response = await fetch( cfg.ajax, { method: 'POST', body: form, credentials: 'same-origin' } );
		var json = await response.json();
		if ( ! json || ! json.success ) {
			throw new Error( json && json.data ? json.data : t.failed );
		}
		return json.data;
	}

	async function runPhase( label, phase, run, total ) {
		el( 'mmseo-phase' ).textContent = label;
		var cursor = {};
		var count = 0;
		for ( ;; ) {
			var data = await call( phase, run, cursor );
			count += data.n || 0;
			cursor = data.cursor || {};
			if ( total ) {
				bar( ( 100 * count ) / total );
			}
			if ( data.msg ) {
				log( data.msg );
			}
			if ( data.done ) {
				return;
			}
		}
	}

	function setBusy( busy ) {
		[ 'mmseo-go', 'mmseo-scan', 'mmseo-save' ].forEach( function ( id ) {
			el( id ).disabled = busy;
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '#mmseo-runs button' ), function ( b ) {
			b.disabled = busy;
		} );
	}

	async function refreshRuns() {
		var data = await call( 'list', '', {} );
		var body = el( 'mmseo-runs' ).querySelector( 'tbody' );
		body.textContent = '';
		if ( ! data.runs.length ) {
			var empty = document.createElement( 'tr' );
			var cell = document.createElement( 'td' );
			cell.colSpan = 5;
			cell.textContent = t.noBackups;
			empty.appendChild( cell );
			body.appendChild( empty );
			return;
		}
		data.runs.forEach( function ( run ) {
			var row = document.createElement( 'tr' );
			[ run.date, run.label, String( run.converted ), run.saved ].forEach( function ( text ) {
				var td = document.createElement( 'td' );
				td.textContent = text;
				row.appendChild( td );
			} );
			var actions = document.createElement( 'td' );
			if ( run.restore ) {
				var restore = document.createElement( 'button' );
				restore.type = 'button';
				restore.className = 'button button-secondary';
				restore.textContent = t.restore;
				restore.addEventListener( 'click', function () {
					restoreRun( run.id );
				} );
				actions.appendChild( restore );
			}
			var del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'button-link-delete button';
			del.textContent = t.delete;
			del.addEventListener( 'click', function () {
				deleteRun( run.id );
			} );
			actions.appendChild( del );
			row.appendChild( actions );
			body.appendChild( row );
		} );
	}

	async function restoreRun( id ) {
		if ( ! window.confirm( t.confirmRestore ) ) {
			return;
		}
		setBusy( true );
		el( 'mmseo-log' ).textContent = '';
		try {
			bar( 5 );
			await runPhase( t.stRestoreFiles, 'restore_files', id, 0 );
			bar( 30 );
			await runPhase( t.stRestoreRecs, 'restore_attachments', id, 0 );
			bar( 55 );
			await runPhase( t.stRestoreRefs, 'restore_refs', id, 0 );
			bar( 80 );
			await runPhase( t.stRestoreClean, 'restore_cleanup', id, 0 );
			bar( 100 );
			el( 'mmseo-phase' ).textContent = t.done;
		} catch ( e ) {
			log( 'ERROR: ' + e.message );
		}
		setBusy( false );
		await refreshRuns();
		setBusy( false );
	}

	async function deleteRun( id ) {
		if ( ! window.confirm( t.confirmDelete ) ) {
			return;
		}
		try {
			await call( 'delete_run', id, {} );
		} catch ( e ) {
			log( 'ERROR: ' + e.message );
		}
		await refreshRuns();
	}

	el( 'mmseo-scan' ).addEventListener( 'click', async function () {
		try {
			var data = await call( 'scan', '', {} );
			log( data.msg );
		} catch ( e ) {
			log( 'ERROR: ' + e.message );
		}
	} );

	el( 'mmseo-save' ).addEventListener( 'click', async function () {
		try {
			await call( 'save', '', {}, settings() );
			log( t.saved );
		} catch ( e ) {
			log( 'ERROR: ' + e.message );
		}
	} );

	el( 'mmseo-go' ).addEventListener( 'click', async function () {
		if ( ! window.confirm( t.confirmGo ) ) {
			return;
		}
		setBusy( true );
		el( 'mmseo-log' ).textContent = '';
		var run = '';
		try {
			var init = await call( 'init', '', {}, settings() );
			run = init.run;
			log( init.msg );
			bar( 0 );
			await runPhase( t.stBackupFiles, 'backup_files', run, init.total );
			bar( 0 );
			await runPhase( t.stBackupDb, 'backup_db', run, 0 );
			bar( 0 );
			await runPhase( t.stConvert, 'convert', run, init.total );
			bar( 100 );
			await runPhase( t.stReplace, 'replace', run, 0 );
			if ( el( 'mmseo-delete' ).checked ) {
				await runPhase( t.stDelete, 'finalize', run, 0 );
			}
			var summary = await call( 'summary', run, {} );
			log( summary.msg );
			el( 'mmseo-phase' ).textContent = t.done;
		} catch ( e ) {
			log( 'ERROR: ' + e.message + ' ' + t.safe );
		}
		setBusy( false );
		await refreshRuns();
		setBusy( false );
	} );

	refreshRuns().catch( function ( e ) {
		log( 'ERROR: ' + e.message );
	} );
}() );
