// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

( function ( wp ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { Button, Notice, Spinner } = wp.components;
	const { createElement: el, render, useRef, useState } = wp.element;
	const apiFetch = wp.apiFetch;

	const NAMESPACE = '/wp-scatter-elsewhere/v1';
	const SCAN_SIZE = 5;
	const APPLY_SIZE = 5;
	const initial = window.wpScatterElsewhereLinkTools || { runs: [] };

	const PRIVACY = {
		private: __( 'Private', 'wp-scatter-elsewhere' ),
		unlisted: __( 'Unlisted', 'wp-scatter-elsewhere' ),
		public: __( 'Public', 'wp-scatter-elsewhere' ),
	};

	const errorMessage = function ( error ) {
		return error && error.message ? error.message : __( 'The request failed.', 'wp-scatter-elsewhere' );
	};

	const formatDate = function ( isoDate ) {
		const date = new Date( isoDate );

		return isNaN( date.getTime() ) ? '' : date.toLocaleDateString();
	};

	const youtubeLink = function ( id, text ) {
		return el( 'a', { href: 'https://www.youtube.com/watch?v=' + id, target: '_blank', rel: 'noopener noreferrer' }, text );
	};

	const toRow = function ( data ) {
		const candidates = data.candidates || [];
		const choice = candidates.find( ( candidate ) => candidate.youtube_id === data.best ) || null;

		return Object.assign( {}, data, {
			key: data.post + ':' + data.video,
			choice: choice,
			ticked: 'would link' === data.decision && null !== choice,
			outcome: null,
		} );
	};

	/**
	 * The proposed video of a row, the other candidates, and a search in the channel to pick another one.
	 */
	const Row = function ( props ) {
		const { row, conflict, onChange } = props;
		const [ searching, setSearching ] = useState( false );
		const [ words, setWords ] = useState( '' );
		const [ results, setResults ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ failure, setFailure ] = useState( '' );

		const search = function () {
			setBusy( true );
			setFailure( '' );

			apiFetch( { path: NAMESPACE + '/youtube/channel-videos?unlinked=1&search=' + encodeURIComponent( words ) } )
				.then( ( response ) => setResults( response.videos ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) )
				.finally( () => setBusy( false ) );
		};

		const pick = function ( video ) {
			onChange( row.key, {
				choice: { youtube_id: video.youtube_id, title: video.title, privacy: video.privacy, published_at: video.published_at, confidence: 'suggestion', reasons: [ __( 'chosen by hand', 'wp-scatter-elsewhere' ) ] },
				ticked: true,
			} );
			setSearching( false );
			setResults( null );
		};

		const choice = row.choice;
		const done = !! ( row.outcome && row.outcome.linked );
		const cells = [];

		cells.push(
			el(
				'td',
				{ key: 'tick' },
				el( 'input', {
					type: 'checkbox',
					checked: row.ticked && ! conflict && ! done,
					disabled: null === choice || conflict || done,
					onChange: ( event ) => onChange( row.key, { ticked: event.target.checked } ),
				} )
			),
			el( 'td', { key: 'post' }, row.edit_url ? el( 'a', { href: row.edit_url }, row.title || row.post ) : row.title, el( 'div', { style: { color: '#757575' } }, row.video ) )
		);

		let proposal;
		if ( null === choice ) {
			proposal = el( 'em', null, __( 'No candidate', 'wp-scatter-elsewhere' ) );
		} else {
			proposal = [
				el( 'strong', { key: 't' }, youtubeLink( choice.youtube_id, choice.title ) ),
				el( 'div', { key: 'd', style: { color: '#757575' } }, [ PRIVACY[ choice.privacy ] || choice.privacy, formatDate( choice.published_at ) ].filter( Boolean ).join( ', ' ) ),
				el( 'div', { key: 'r', style: { color: '#757575' } }, ( choice.reasons || [] ).join( ', ' ) ),
			];
		}

		const others = ( row.candidates || [] ).filter( ( candidate ) => ! choice || candidate.youtube_id !== choice.youtube_id );

		cells.push(
			el(
				'td',
				{ key: 'proposal' },
				proposal,
				others.length
					? el(
						'div',
						{ style: { marginTop: '4px' } },
						others.map( ( candidate ) =>
							el( Button, { key: candidate.youtube_id, variant: 'link', onClick: () => onChange( row.key, { choice: candidate, ticked: 'high' === candidate.confidence } ) }, sprintf(
								/* translators: %s: title of a video. */
								__( 'Use "%s"', 'wp-scatter-elsewhere' ),
								candidate.title
							) ),
							' '
						)
					)
					: null,
				el( 'div', { style: { marginTop: '4px' } }, el( Button, { variant: 'link', onClick: () => setSearching( ! searching ) }, __( 'Choose another', 'wp-scatter-elsewhere' ) ) ),
				searching
					? el(
						'div',
						{ style: { marginTop: '4px' } },
						el( 'input', { type: 'text', value: words, 'aria-label': __( 'Words of the title', 'wp-scatter-elsewhere' ), onChange: ( event ) => setWords( event.target.value ) } ),
						' ',
						el( Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: search }, __( 'Search', 'wp-scatter-elsewhere' ) ),
						failure ? el( 'p', { style: { color: '#b32d2e' } }, failure ) : null,
						results && 0 === results.length ? el( 'p', null, __( 'No video found.', 'wp-scatter-elsewhere' ) ) : null,
						results && results.length
							? el(
								'ul',
								{ style: { margin: '4px 0 0', maxHeight: '12em', overflow: 'auto' } },
								results.map( ( video ) => el( 'li', { key: video.youtube_id }, el( Button, { variant: 'link', onClick: () => pick( video ) }, video.title ), ' ', el( 'span', { style: { color: '#757575' } }, formatDate( video.published_at ) ) ) )
							)
							: null
					)
					: null
			)
		);

		let status = row.note || '';
		if ( conflict ) {
			status = __( 'Claimed by several videos of the blog: choose another video for one of them.', 'wp-scatter-elsewhere' );
		}
		if ( row.outcome ) {
			status = row.outcome.linked ? __( 'Linked', 'wp-scatter-elsewhere' ) : row.outcome.message;
		}

		cells.push( el( 'td', { key: 'status', style: { color: row.outcome && ! row.outcome.linked ? '#b32d2e' : undefined } }, status ) );

		return el( 'tr', null, cells );
	};

	const RowTable = function ( props ) {
		return el(
			'table',
			{ className: 'widefat striped' },
			el( 'thead', null, el( 'tr', null, [ '', __( 'Post', 'wp-scatter-elsewhere' ), __( 'YouTube video', 'wp-scatter-elsewhere' ), __( 'Status', 'wp-scatter-elsewhere' ) ].map( ( title, index ) => el( 'th', { key: index }, title ) ) ) ),
			el( 'tbody', null, props.rows.map( ( row ) => el( Row, { key: row.key, row: row, conflict: props.conflicts.has( row.key ), onChange: props.onChange } ) ) )
		);
	};

	const App = function () {
		const [ since, setSince ] = useState( '' );
		const [ minConfidence, setMinConfidence ] = useState( 'high' );
		const [ unlinkedOnly, setUnlinkedOnly ] = useState( true );
		const [ limit, setLimit ] = useState( '' );
		const [ rows, setRows ] = useState( [] );
		const [ errors, setErrors ] = useState( [] );
		const [ progress, setProgress ] = useState( null );
		const [ scanning, setScanning ] = useState( false );
		const [ applying, setApplying ] = useState( false );
		const [ failure, setFailure ] = useState( '' );
		const [ quota, setQuota ] = useState( null );
		const [ runs, setRuns ] = useState( initial.runs || [] );
		const [ runId, setRunId ] = useState( '' );
		const stop = useRef( false );

		const change = function ( key, patch ) {
			setRows( ( current ) => current.map( ( row ) => ( row.key === key ? Object.assign( {}, row, patch ) : row ) ) );
		};

		// A YouTube video chosen for several videos of the blog is linked to none of them.
		const conflicts = new Set();
		const owners = {};
		rows.forEach( ( row ) => {
			if ( row.choice && ! ( row.outcome && row.outcome.linked ) ) {
				( owners[ row.choice.youtube_id ] = owners[ row.choice.youtube_id ] || [] ).push( row.key );
			}
		} );
		Object.keys( owners ).forEach( ( id ) => owners[ id ].length > 1 && owners[ id ].forEach( ( key ) => conflicts.add( key ) ) );

		const scan = async function () {
			stop.current = false;
			setScanning( true );
			setFailure( '' );
			setRows( [] );
			setErrors( [] );

			let cursor = 0;
			try {
				do {
					const response = await apiFetch( {
						path: NAMESPACE + '/tools/link-scan',
						method: 'POST',
						data: { cursor: cursor, size: SCAN_SIZE, since: since, min_confidence: minConfidence, unlinked_only: unlinkedOnly, limit: limit ? parseInt( limit, 10 ) : 0, refresh: 0 === cursor },
					} );

					setRows( ( current ) => current.concat( response.rows.map( toRow ).filter( ( row ) => ! current.some( ( other ) => other.key === row.key ) ) ) );
					setErrors( ( current ) => current.concat( response.errors ) );
					setProgress( { done: null === response.next ? response.total : response.next, total: response.total } );
					setQuota( response.quota );
					cursor = response.next;
				} while ( null !== cursor && ! stop.current );
			} catch ( error ) {
				setFailure( errorMessage( error ) );
			}

			setScanning( false );
		};

		const ticked = rows.filter( ( row ) => row.ticked && row.choice && ! conflicts.has( row.key ) && ! ( row.outcome && row.outcome.linked ) );

		const apply = async function () {
			setApplying( true );
			setFailure( '' );

			let id = runId;
			try {
				for ( let start = 0; start < ticked.length; start += APPLY_SIZE ) {
					const part = ticked.slice( start, start + APPLY_SIZE );
					const response = await apiFetch( {
						path: NAMESPACE + '/tools/link-apply',
						method: 'POST',
						data: { run_id: id, items: part.map( ( row ) => ( { post: row.post, video: row.video, youtube: row.choice.youtube_id } ) ) },
					} );

					id = response.run_id || id;
					setRunId( id );
					setRuns( response.runs );
					response.outcomes.forEach( ( outcome ) => change( outcome.post + ':' + outcome.video, { outcome: { linked: outcome.linked, message: outcome.message }, ticked: false } ) );
				}
			} catch ( error ) {
				setFailure( errorMessage( error ) );
			}

			setApplying( false );
		};

		const undo = function ( run, items ) {
			setFailure( '' );

			apiFetch( { path: NAMESPACE + '/tools/link-undo', method: 'POST', data: { run_id: run.id, items: items || [] } } )
				.then( ( response ) => setRuns( response.runs ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) );
		};

		const main = rows.filter( ( row ) => ! row.linked && ( 'no candidate' !== row.decision || ( row.outcome && row.outcome.linked ) ) );
		const nothing = rows.filter( ( row ) => ! row.linked && 'no candidate' === row.decision && ! ( row.outcome && row.outcome.linked ) );
		const already = rows.filter( ( row ) => row.linked );
		const children = [];

		children.push(
			el(
				'div',
				{ key: 'filters', style: { margin: '12px 0' } },
				el( 'label', null, __( 'Posts since', 'wp-scatter-elsewhere' ) + ' ', el( 'input', { type: 'date', value: since, onChange: ( event ) => setSince( event.target.value ) } ) ),
				' ',
				el( 'label', null, __( 'Number of posts (0 for all)', 'wp-scatter-elsewhere' ) + ' ', el( 'input', { type: 'number', min: 0, className: 'small-text', value: limit, onChange: ( event ) => setLimit( event.target.value ) } ) ),
				' ',
				el(
					'label',
					null,
					__( 'Tick by default', 'wp-scatter-elsewhere' ) + ' ',
					el( 'select', { value: minConfidence, onChange: ( event ) => setMinConfidence( event.target.value ) }, el( 'option', { value: 'high' }, __( 'confident matches only', 'wp-scatter-elsewhere' ) ), el( 'option', { value: 'suggestion' }, __( 'all the proposals', 'wp-scatter-elsewhere' ) ) )
				),
				' ',
				el( 'label', null, el( 'input', { type: 'checkbox', checked: unlinkedOnly, onChange: ( event ) => setUnlinkedOnly( event.target.checked ) } ), ' ', __( 'Only posts without a linked video', 'wp-scatter-elsewhere' ) ),
				el(
					'p',
					null,
					el( Button, { variant: 'primary', isBusy: scanning, disabled: scanning || applying, onClick: scan }, __( 'Scan', 'wp-scatter-elsewhere' ) ),
					' ',
					scanning ? el( Button, { variant: 'secondary', onClick: () => { stop.current = true; } }, __( 'Stop', 'wp-scatter-elsewhere' ) ) : null
				)
			)
		);

		if ( failure ) {
			children.push( el( Notice, { key: 'failure', status: 'error', isDismissible: false }, failure ) );
		}

		if ( quota && 'ok' !== quota.level ) {
			children.push( el( Notice, { key: 'quota', status: 'exhausted' === quota.level ? 'error' : 'warning', isDismissible: false }, 'exhausted' === quota.level
				? __( 'The daily YouTube quota is exhausted.', 'wp-scatter-elsewhere' )
				: sprintf(
					/* translators: %d: number of units. */
					__( 'About %d units of the daily YouTube quota are left (estimate).', 'wp-scatter-elsewhere' ),
					quota.remaining
				) ) );
		}

		if ( progress ) {
			children.push(
				el(
					'p',
					{ key: 'progress' },
					el( 'progress', { max: progress.total || 1, value: progress.done, style: { width: '20em' } } ),
					' ',
					sprintf(
						/* translators: 1: posts examined, 2: posts to examine. */
						__( '%1$d of %2$d posts examined', 'wp-scatter-elsewhere' ),
						progress.done,
						progress.total
					),
					scanning ? el( Spinner ) : null
				)
			);
		}

		errors.forEach( ( error, index ) => children.push( el( Notice, { key: 'error' + index, status: 'warning', isDismissible: false }, sprintf(
			/* translators: 1: post title, 2: reason. */
			__( '%1$s: %2$s', 'wp-scatter-elsewhere' ),
			error.title || error.post,
			error.message
		) ) ) );

		if ( main.length ) {
			children.push(
				el( RowTable, { key: 'main', rows: main, conflicts: conflicts, onChange: change } ),
				el(
					'p',
					{ key: 'apply' },
					el( Button, { variant: 'primary', isBusy: applying, disabled: applying || scanning || 0 === ticked.length, onClick: apply }, sprintf(
						/* translators: %d: number of videos. */
						__( 'Link the %d ticked video(s)', 'wp-scatter-elsewhere' ),
						ticked.length
					) )
				)
			);
		} else if ( progress && ! scanning ) {
			children.push( el( 'p', { key: 'none' }, __( 'No post with a video to link was found.', 'wp-scatter-elsewhere' ) ) );
		}

		if ( nothing.length ) {
			children.push( el( 'details', { key: 'nothing', style: { marginTop: '12px' } }, el( 'summary', null, sprintf(
				/* translators: %d: number of videos. */
				__( '%d video(s) without a candidate', 'wp-scatter-elsewhere' ),
				nothing.length
			) ), el( RowTable, { rows: nothing, conflicts: conflicts, onChange: change } ) ) );
		}

		if ( already.length ) {
			children.push( el( 'details', { key: 'already', style: { marginTop: '12px' } }, el( 'summary', null, sprintf(
				/* translators: %d: number of videos. */
				__( '%d video(s) already linked', 'wp-scatter-elsewhere' ),
				already.length
			) ), el( 'ul', null, already.map( ( row ) => el( 'li', { key: row.key }, row.edit_url ? el( 'a', { href: row.edit_url }, row.title || row.post ) : row.title ) ) ) ) );
		}

		if ( runs.length ) {
			children.push(
				el( 'h2', { key: 'runsTitle', style: { marginTop: '24px' } }, __( 'Recent links', 'wp-scatter-elsewhere' ) ),
				runs.map( ( run ) =>
					el(
						'div',
						{ key: run.id, style: { marginBottom: '12px' } },
						el( 'strong', null, new Date( run.time * 1000 ).toLocaleString() ),
						' ',
						el( Button, { variant: 'secondary', onClick: () => undo( run, [] ) }, __( 'Undo all this run', 'wp-scatter-elsewhere' ) ),
						el( 'ul', null, run.items.map( ( item ) => el( 'li', { key: item.post + ':' + item.video }, ( item.title || item.post ) + ' → ', youtubeLink( item.youtube, item.youtube ), ' ', el( Button, { variant: 'link', onClick: () => undo( run, [ item ] ) }, __( 'Undo', 'wp-scatter-elsewhere' ) ) ) ) )
					)
				)
			);
		}

		return el( 'div', null, children );
	};

	const root = document.getElementById( 'wpse-link-tools' );
	if ( root ) {
		render( el( App ), root );
	}
}( window.wp ) );
