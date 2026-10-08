// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

( function ( wp ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { Button, Notice, Spinner } = wp.components;
	const { createElement: el, render, useEffect, useRef, useState } = wp.element;
	const apiFetch = wp.apiFetch;

	const NAMESPACE = '/wp-scatter-elsewhere/v1/tools/';
	const POLL_MS = 5000;
	const PREVIEW_SIZE = 5;
	const initial = window.wpScatterElsewhereUpdateTools || { terms: [] };

	const FIELDS = [
		{ id: 'language', label: __( 'Language', 'wp-scatter-elsewhere' ) },
		{ id: 'license', label: __( 'License', 'wp-scatter-elsewhere' ) },
		{ id: 'recording_date', label: __( 'Recording date', 'wp-scatter-elsewhere' ) },
		{ id: 'category', label: __( 'Category', 'wp-scatter-elsewhere' ) },
		{ id: 'embeddable', label: __( 'Embedding allowed', 'wp-scatter-elsewhere' ) },
		{ id: 'public_stats', label: __( 'Public statistics', 'wp-scatter-elsewhere' ) },
		{ id: 'made_for_kids', label: __( 'Made for kids', 'wp-scatter-elsewhere' ) },
		{ id: 'keywords', label: __( 'Keywords', 'wp-scatter-elsewhere' ) },
		{ id: 'playlists', label: __( 'Playlists', 'wp-scatter-elsewhere' ) },
		{ id: 'thumbnail', label: __( 'Thumbnail (sent again)', 'wp-scatter-elsewhere' ) },
		{ id: 'subtitles', label: __( 'Subtitles (sent again)', 'wp-scatter-elsewhere' ) },
		{ id: 'title', label: __( 'Title', 'wp-scatter-elsewhere' ), warning: __( 'rewrites the title of every video', 'wp-scatter-elsewhere' ) },
		{ id: 'description', label: __( 'Description', 'wp-scatter-elsewhere' ), warning: __( 'rewrites the description of every video', 'wp-scatter-elsewhere' ) },
		{ id: 'privacy', label: __( 'Privacy', 'wp-scatter-elsewhere' ), warning: __( 'publishes or hides videos', 'wp-scatter-elsewhere' ) },
	];

	const STATUS = {
		queued: __( 'Running', 'wp-scatter-elsewhere' ),
		paused: __( 'Paused until the quota is reset', 'wp-scatter-elsewhere' ),
		stopped: __( 'Stopped', 'wp-scatter-elsewhere' ),
		done: __( 'Done', 'wp-scatter-elsewhere' ),
	};

	const errorMessage = function ( error ) {
		return error && error.message ? error.message : __( 'The request failed.', 'wp-scatter-elsewhere' );
	};

	const csvCell = function ( value ) {
		return '"' + String( value === undefined || value === null ? '' : value ).replace( /"/g, '""' ) + '"';
	};

	const download = function ( jobId, entries ) {
		const header = [ 'time', 'post', 'title', 'video', 'youtube', 'field', 'previous value', 'new value', 'action' ];
		const lines = [ header.map( csvCell ).join( ',' ) ].concat(
			entries.map( ( entry ) => [ new Date( entry.time * 1000 ).toISOString(), entry.post, entry.title, entry.video, entry.youtube, entry.field, entry.current, entry.new, entry.action ].map( csvCell ).join( ',' ) )
		);
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( new Blob( [ lines.join( '\n' ) ], { type: 'text/csv' } ) );
		link.download = 'scatter-elsewhere-update-' + jobId + '.csv';
		document.body.appendChild( link );
		link.click();
		link.remove();
	};

	const isActive = function ( job ) {
		return 'queued' === job.status || 'paused' === job.status;
	};

	const App = function () {
		const [ fields, setFields ] = useState( [] );
		const [ keywords, setKeywords ] = useState( 'add' );
		const [ playlists, setPlaylists ] = useState( 'add' );
		const [ privacy, setPrivacy ] = useState( 'private' );
		const [ since, setSince ] = useState( '' );
		const [ until, setUntil ] = useState( '' );
		const [ term, setTerm ] = useState( '' );
		const [ posts, setPosts ] = useState( '' );
		const [ limit, setLimit ] = useState( '' );
		const [ preview, setPreview ] = useState( null );
		const [ previewing, setPreviewing ] = useState( false );
		const [ state, setState ] = useState( { jobs: [], quota: null } );
		const [ failure, setFailure ] = useState( '' );
		const stop = useRef( false );

		const refresh = function () {
			return apiFetch( { path: NAMESPACE + 'update-status' } )
				.then( ( response ) => setState( response ) )
				.catch( () => {} );
		};

		useEffect( () => {
			refresh();
		}, [] );

		const active = state.jobs.find( isActive ) || null;

		useEffect( () => {
			if ( ! active ) {
				return undefined;
			}

			const timer = window.setInterval( refresh, POLL_MS );

			return () => window.clearInterval( timer );
		}, [ active ? active.id : '' ] );

		const plan = function () {
			return {
				fields: fields,
				keywords: keywords,
				playlists: playlists,
				privacy: fields.includes( 'privacy' ) ? privacy : '',
				posts: posts,
				since: since,
				until: until,
				term: term,
				limit: limit ? parseInt( limit, 10 ) : 0,
			};
		};

		const toggle = function ( id, checked ) {
			setFields( ( current ) => ( checked ? current.concat( id ) : current.filter( ( field ) => field !== id ) ) );
			setPreview( null );
		};

		const runPreview = async function () {
			stop.current = false;
			setPreviewing( true );
			setFailure( '' );
			setPreview( { rows: [], examined: 0, changed: 0, errors: 0, cost: 0, total: 0 } );

			let cursor = 0;
			try {
				do {
					const response = await apiFetch( { path: NAMESPACE + 'update-preview', method: 'POST', data: Object.assign( plan(), { cursor: cursor, size: PREVIEW_SIZE } ) } );

					setPreview( ( current ) => ( {
						rows: current.rows.concat( response.rows ),
						examined: current.examined + response.examined,
						changed: current.changed + response.changed,
						errors: current.errors + response.errors,
						cost: current.cost + response.cost,
						total: response.total,
					} ) );
					setState( ( current ) => Object.assign( {}, current, { quota: response.quota } ) );
					cursor = response.next;
				} while ( null !== cursor && ! stop.current );
			} catch ( error ) {
				setFailure( errorMessage( error ) );
			}

			setPreviewing( false );
		};

		const start = function () {
			const risky = fields.filter( ( id ) => FIELDS.find( ( field ) => field.id === id && field.warning ) );
			const message = risky.length
				? sprintf(
					/* translators: %s: names of fields. */
					__( 'This update rewrites: %s. Start it?', 'wp-scatter-elsewhere' ),
					risky.join( ', ' )
				)
				: __( 'Start the update of the videos now?', 'wp-scatter-elsewhere' );

			if ( ! window.confirm( message ) ) {
				return;
			}

			setFailure( '' );
			apiFetch( { path: NAMESPACE + 'update-start', method: 'POST', data: plan() } )
				.then( ( response ) => setState( response ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) );
		};

		const control = function ( route, job ) {
			setFailure( '' );
			apiFetch( { path: NAMESPACE + route, method: 'POST', data: { job: job.id } } )
				.then( ( response ) => setState( response ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) );
		};

		const exportLog = function ( job ) {
			apiFetch( { path: NAMESPACE + 'update-log?job=' + encodeURIComponent( job.id ) } )
				.then( ( response ) => download( job.id, response.entries ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) );
		};

		const children = [];

		children.push(
			el(
				'fieldset',
				{ key: 'fields', style: { margin: '12px 0' } },
				el( 'legend', null, el( 'strong', null, __( 'What to update', 'wp-scatter-elsewhere' ) ) ),
				FIELDS.map( ( field ) =>
					el(
						'div',
						{ key: field.id },
						el( 'label', null, el( 'input', { type: 'checkbox', checked: fields.includes( field.id ), onChange: ( event ) => toggle( field.id, event.target.checked ) } ), ' ', field.label ),
						field.warning ? el( 'span', { style: { color: '#b32d2e' } }, ' — ' + field.warning ) : null
					)
				),
				fields.includes( 'privacy' )
					? el( 'p', null, __( 'Set the privacy to', 'wp-scatter-elsewhere' ) + ' ', el( 'select', { value: privacy, onChange: ( event ) => setPrivacy( event.target.value ) }, [ 'private', 'unlisted', 'public' ].map( ( value ) => el( 'option', { key: value, value: value }, value ) ) ) )
					: null,
				fields.includes( 'playlists' )
					? el( 'p', null, __( 'Playlists', 'wp-scatter-elsewhere' ) + ' ', el( 'select', { value: playlists, onChange: ( event ) => setPlaylists( event.target.value ) }, el( 'option', { value: 'add' }, __( 'only add', 'wp-scatter-elsewhere' ) ), el( 'option', { value: 'sync' }, __( 'add and remove those that no longer apply', 'wp-scatter-elsewhere' ) ) ), el( 'span', { className: 'description' }, ' ' + __( 'Only the playlists named by a rule are ever removed.', 'wp-scatter-elsewhere' ) ) )
					: null,
				fields.includes( 'keywords' )
					? el( 'p', null, __( 'Keywords', 'wp-scatter-elsewhere' ) + ' ', el( 'select', { value: keywords, onChange: ( event ) => setKeywords( event.target.value ) }, el( 'option', { value: 'add' }, __( 'only add', 'wp-scatter-elsewhere' ) ), el( 'option', { value: 'sync' }, __( 'add and remove those that no longer apply', 'wp-scatter-elsewhere' ) ) ), el( 'span', { className: 'description' }, ' ' + __( 'Only the keywords named by a rule are ever removed.', 'wp-scatter-elsewhere' ) ) )
					: null
			),
			el(
				'fieldset',
				{ key: 'selection', style: { margin: '12px 0' } },
				el( 'legend', null, el( 'strong', null, __( 'Which videos', 'wp-scatter-elsewhere' ) ) ),
				el( 'label', null, __( 'Posts since', 'wp-scatter-elsewhere' ) + ' ', el( 'input', { type: 'date', value: since, onChange: ( event ) => setSince( event.target.value ) } ) ),
				' ',
				el( 'label', null, __( 'until', 'wp-scatter-elsewhere' ) + ' ', el( 'input', { type: 'date', value: until, onChange: ( event ) => setUntil( event.target.value ) } ) ),
				' ',
				el(
					'label',
					null,
					__( 'Posts with', 'wp-scatter-elsewhere' ) + ' ',
					el( 'select', { value: term, onChange: ( event ) => setTerm( event.target.value ) }, el( 'option', { value: '' }, __( 'any term', 'wp-scatter-elsewhere' ) ), initial.terms.map( ( item ) => el( 'option', { key: item.value, value: item.value }, item.label ) ) )
				),
				el( 'br' ),
				el( 'label', null, __( 'Post IDs', 'wp-scatter-elsewhere' ) + ' ', el( 'input', { type: 'text', placeholder: '12, 34', value: posts, onChange: ( event ) => setPosts( event.target.value ) } ) ),
				' ',
				el( 'label', null, __( 'At most (0 for all)', 'wp-scatter-elsewhere' ) + ' ', el( 'input', { type: 'number', min: 0, className: 'small-text', value: limit, onChange: ( event ) => setLimit( event.target.value ) } ) )
			),
			el(
				'p',
				{ key: 'buttons' },
				el( Button, { variant: 'secondary', isBusy: previewing, disabled: previewing || 0 === fields.length, onClick: runPreview }, __( 'Preview', 'wp-scatter-elsewhere' ) ),
				' ',
				previewing ? el( Button, { variant: 'tertiary', onClick: () => { stop.current = true; } }, __( 'Stop the preview', 'wp-scatter-elsewhere' ) ) : null,
				' ',
				el( Button, { variant: 'primary', disabled: 0 === fields.length || previewing || !! active, onClick: start }, __( 'Start the update', 'wp-scatter-elsewhere' ) )
			)
		);

		if ( failure ) {
			children.push( el( Notice, { key: 'failure', status: 'error', isDismissible: false }, failure ) );
		}

		const quota = state.quota;
		if ( quota && 'ok' !== quota.level ) {
			children.push( el( Notice, { key: 'quota', status: 'exhausted' === quota.level ? 'error' : 'warning', isDismissible: false }, 'exhausted' === quota.level
				? __( 'The daily YouTube quota is exhausted: a running update waits for the reset.', 'wp-scatter-elsewhere' )
				: sprintf(
					/* translators: %d: number of units. */
					__( 'About %d units of the daily YouTube quota are left (estimate).', 'wp-scatter-elsewhere' ),
					quota.remaining
				) ) );
		}

		if ( preview ) {
			children.push(
				el( 'h2', { key: 'previewTitle' }, __( 'Preview', 'wp-scatter-elsewhere' ) ),
				el(
					'p',
					{ key: 'previewSummary' },
					sprintf(
						/* translators: 1: videos examined, 2: videos selected, 3: videos with differences, 4: errors, 5: quota units. */
						__( '%1$d of %2$d video(s) examined, %3$d with differences, %4$d error(s). The update would use about %5$d quota units.', 'wp-scatter-elsewhere' ),
						preview.examined,
						preview.total,
						preview.changed,
						preview.errors,
						preview.cost
					),
					previewing ? el( Spinner ) : null
				)
			);

			if ( preview.rows.length ) {
				children.push(
					el(
						'table',
						{ key: 'previewTable', className: 'widefat striped' },
						el( 'thead', null, el( 'tr', null, [ __( 'Post', 'wp-scatter-elsewhere' ), __( 'Field', 'wp-scatter-elsewhere' ), __( 'Now', 'wp-scatter-elsewhere' ), __( 'New value', 'wp-scatter-elsewhere' ), __( 'Action', 'wp-scatter-elsewhere' ) ].map( ( title, index ) => el( 'th', { key: index }, title ) ) ) ),
						el( 'tbody', null, preview.rows.map( ( row, index ) => el( 'tr', { key: index }, [ row.title || row.post, row.field, row.current, row.new, row.action ].map( ( cell, position ) => el( 'td', { key: position, style: { wordBreak: 'break-word' } }, cell ) ) ) ) )
					)
				);
			} else if ( ! previewing ) {
				children.push( el( 'p', { key: 'previewNone' }, __( 'Nothing would change.', 'wp-scatter-elsewhere' ) ) );
			}
		}

		if ( state.jobs.length ) {
			children.push( el( 'h2', { key: 'jobsTitle', style: { marginTop: '24px' } }, __( 'Updates', 'wp-scatter-elsewhere' ) ) );

			state.jobs.forEach( ( job ) => {
				children.push(
					el(
						'div',
						{ key: job.id, style: { marginBottom: '16px', padding: '8px 12px', background: '#fff', border: '1px solid #c3c4c7' } },
						el( 'strong', null, new Date( job.created_at * 1000 ).toLocaleString() ),
						' — ',
						STATUS[ job.status ] || job.status,
						el( 'div', { style: { color: '#757575' } }, job.plan.fields.join( ', ' ) ),
						el( 'progress', { max: job.total || 1, value: job.cursor, style: { width: '20em' } } ),
						' ',
						sprintf(
							/* translators: 1: videos done, 2: videos in the job, 3: changed, 4: unchanged, 5: errors, 6: quota units. */
							__( '%1$d of %2$d — %3$d changed, %4$d unchanged, %5$d error(s), about %6$d quota units', 'wp-scatter-elsewhere' ),
							job.cursor,
							job.total,
							job.changed,
							job.unchanged,
							job.errors,
							job.cost
						),
						job.message ? el( 'div', null, job.message ) : null,
						'paused' === job.status && job.pause_until ? el( 'div', null, sprintf(
							/* translators: %s: date and time. */
							__( 'Resumes on %s', 'wp-scatter-elsewhere' ),
							new Date( job.pause_until * 1000 ).toLocaleString()
						) ) : null,
						el(
							'p',
							null,
							isActive( job ) ? el( Button, { variant: 'secondary', onClick: () => control( 'update-stop', job ) }, __( 'Stop', 'wp-scatter-elsewhere' ) ) : null,
							'stopped' === job.status ? el( Button, { variant: 'secondary', onClick: () => control( 'update-resume', job ) }, __( 'Resume', 'wp-scatter-elsewhere' ) ) : null,
							' ',
							el( Button, { variant: 'tertiary', onClick: () => exportLog( job ) }, __( 'Export the log (CSV)', 'wp-scatter-elsewhere' ) )
						)
					)
				);
			} );
		}

		return el( 'div', null, children );
	};

	const root = document.getElementById( 'wpse-update-tools' );
	if ( root ) {
		render( el( App ), root );
	}
}( window.wp ) );
