// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

( function ( wp ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { Button, Notice, SelectControl, Spinner } = wp.components;
	const { useSelect } = wp.data;
	const { createElement: el, useEffect, useState } = wp.element;
	const apiFetch = wp.apiFetch;

	// Since WordPress 6.6 the slots of the editor are exported by wp.editor; before, by wp.editPost.
	const slots = Object.assign( {}, wp.editPost || {}, wp.editor || {} );
	const PluginPostPublishPanel = slots.PluginPostPublishPanel;
	const PluginDocumentSettingPanel = slots.PluginDocumentSettingPanel;

	const NAMESPACE = '/wp-scatter-elsewhere/v1';
	const POLL_INTERVAL_MS = 5000;
	const ACTIVE_STATUSES = [ 'queued', 'uploading', 'retry' ];

	const PRIVACY_OPTIONS = [
		{ value: 'private', label: __( 'Private', 'wp-scatter-elsewhere' ) },
		{ value: 'unlisted', label: __( 'Unlisted', 'wp-scatter-elsewhere' ) },
		{ value: 'public', label: __( 'Public', 'wp-scatter-elsewhere' ) },
	];
	const LICENSE_OPTIONS = [
		{ value: 'youtube', label: __( 'Standard YouTube License', 'wp-scatter-elsewhere' ) },
		{ value: 'creativeCommon', label: __( 'Creative Commons - Attribution', 'wp-scatter-elsewhere' ) },
	];
	const STATUS_LABELS = {
		queued: __( 'Waiting to start', 'wp-scatter-elsewhere' ),
		uploading: __( 'Uploading', 'wp-scatter-elsewhere' ),
		retry: __( 'Waiting to retry', 'wp-scatter-elsewhere' ),
		failed: __( 'Failed', 'wp-scatter-elsewhere' ),
	};

	const privacyLabel = function ( privacy ) {
		const option = PRIVACY_OPTIONS.find( ( candidate ) => candidate.value === privacy );

		return option ? option.label : '';
	};

	const formatSize = function ( bytes ) {
		if ( null === bytes || undefined === bytes ) {
			return '';
		}

		if ( bytes >= 1073741824 ) {
			/* translators: %s: size in gigabytes. */
			return sprintf( __( '%s GB', 'wp-scatter-elsewhere' ), ( bytes / 1073741824 ).toFixed( 1 ) );
		}

		/* translators: %s: size in megabytes. */
		return sprintf( __( '%s MB', 'wp-scatter-elsewhere' ), Math.max( 1, Math.round( bytes / 1048576 ) ) );
	};

	const errorMessage = function ( error ) {
		return error && error.message ? error.message : __( 'The request failed.', 'wp-scatter-elsewhere' );
	};

	/**
	 * One video of the post: its details, and its state or the controls to upload it.
	 */
	const VideoRow = function ( props ) {
		const { video, privacy, license, busy, onUpload, onRetry } = props;
		const job = video.job;
		const active = job && ACTIVE_STATUSES.includes( job.status );
		const languages = ( video.subtitles || [] )
			.filter( ( track ) => track.usable )
			.map( ( track ) => track.language );

		const children = [
			el(
				'p',
				{ key: 'name', style: { margin: '0 0 4px', fontWeight: 600, wordBreak: 'break-all' } },
				video.name,
				video.size ? ' (' + formatSize( video.size ) + ')' : ''
			),
		];

		if ( languages.length ) {
			children.push(
				el(
					'p',
					{ key: 'subtitles', style: { margin: '0 0 4px' } },
					/* translators: %s: comma-separated language codes. */
					sprintf( __( 'Subtitles: %s', 'wp-scatter-elsewhere' ), languages.join( ', ' ) )
				)
			);
		}

		if ( video.youtube ) {
			children.push(
				el(
					'p',
					{ key: 'youtube', style: { margin: '0 0 4px' } },
					el( 'a', { href: video.youtube.url, target: '_blank', rel: 'noopener noreferrer' }, __( 'Watch on YouTube', 'wp-scatter-elsewhere' ) ),
					video.youtube.privacy ? ' (' + privacyLabel( video.youtube.privacy ) + ')' : ''
				)
			);
		} else if ( active ) {
			children.push(
				el(
					'div',
					{ key: 'progress' },
					el( 'p', { style: { margin: '0 0 4px' } }, STATUS_LABELS[ job.status ] + ' - ' + job.progress + '%' ),
					el( 'progress', { max: 100, value: job.progress, style: { width: '100%' } } )
				)
			);
			if ( job.error ) {
				children.push( el( 'p', { key: 'jobError', style: { margin: '4px 0 0', color: '#757575' } }, job.error ) );
			}
		} else if ( job && 'failed' === job.status ) {
			children.push(
				el( Notice, { key: 'failed', status: 'error', isDismissible: false }, ( job.error || STATUS_LABELS.failed ) ),
				el(
					Button,
					{ key: 'retry', variant: 'secondary', disabled: !! busy, onClick: () => onRetry( job.id ) },
					__( 'Retry', 'wp-scatter-elsewhere' )
				)
			);
		} else if ( ! video.uploadable ) {
			children.push( el( 'p', { key: 'reason', style: { margin: 0, color: '#757575' } }, video.reason || '' ) );
		} else {
			children.push(
				el(
					Button,
					{
						key: 'upload',
						variant: 'primary',
						isBusy: busy === video.id,
						disabled: !! busy,
						onClick: () => onUpload( video.id ),
					},
					__( 'Publish to YouTube', 'wp-scatter-elsewhere' )
				)
			);
		}

		if ( video.job && video.job.warning && ! active ) {
			children.push( el( Notice, { key: 'warning', status: 'warning', isDismissible: false }, video.job.warning ) );
		}

		return el( 'div', { style: { marginBottom: '16px' } }, children );
	};

	// The panel is shown after publication and in the sidebar, possibly at the same time: both share one
	// state, one loading and one polling, so that an action in one is seen by the other.
	const store = {
		state: { data: null, loading: false, error: '', busy: '', privacy: '', license: '' },
		listeners: new Set(),
		loadedPostId: null,
		pollTimer: null,
	};

	const setState = function ( patch ) {
		store.state = Object.assign( {}, store.state, patch );
		store.listeners.forEach( ( listener ) => listener() );
	};

	const useStoreState = function () {
		const [ , refresh ] = useState( 0 );

		useEffect( () => {
			const listener = () => refresh( ( count ) => count + 1 );
			store.listeners.add( listener );

			return () => store.listeners.delete( listener );
		}, [] );

		return store.state;
	};

	const mergeStatuses = function ( statuses ) {
		const data = store.state.data;

		if ( ! data ) {
			return;
		}

		setState( {
			data: Object.assign( {}, data, {
				videos: data.videos.map( ( video ) => ( statuses[ video.id ] ? Object.assign( {}, video, statuses[ video.id ] ) : video ) ),
			} ),
		} );
	};

	const hasActiveJob = function () {
		const data = store.state.data;

		return !! data && data.videos.some( ( video ) => video.job && ACTIVE_STATUSES.includes( video.job.status ) );
	};

	// Polls the jobs while an upload is in progress, and only then.
	const syncPolling = function ( postId ) {
		if ( hasActiveJob() && null === store.pollTimer ) {
			store.pollTimer = window.setInterval( () => {
				apiFetch( { path: NAMESPACE + '/post/' + postId + '/youtube/jobs' } )
					.then( ( response ) => {
						mergeStatuses( response.videos );
						syncPolling( postId );
					} )
					.catch( () => {} );
			}, POLL_INTERVAL_MS );
		} else if ( ! hasActiveJob() && null !== store.pollTimer ) {
			window.clearInterval( store.pollTimer );
			store.pollTimer = null;
		}
	};

	const load = function ( postId ) {
		store.loadedPostId = postId;
		setState( { loading: true, error: '' } );

		return apiFetch( { path: NAMESPACE + '/post/' + postId + '/youtube' } )
			.then( ( response ) => {
				setState( {
					data: response,
					privacy: store.state.privacy || response.defaults.privacy,
					license: store.state.license || response.defaults.license,
				} );
				syncPolling( postId );
			} )
			.catch( ( failure ) => {
				store.loadedPostId = null;
				setState( { error: errorMessage( failure ) } );
			} )
			.finally( () => setState( { loading: false } ) );
	};

	const send = function ( postId, busyKey, request ) {
		setState( { busy: busyKey, error: '' } );

		return apiFetch( request )
			.then( ( response ) => {
				mergeStatuses( response.videos );
				syncPolling( postId );
			} )
			.catch( ( failure ) => setState( { error: errorMessage( failure ) } ) )
			.finally( () => setState( { busy: '' } ) );
	};

	/**
	 * The content of the panel, used both after publication and in the sidebar.
	 */
	const YouTubePanel = function () {
		const { postId, isPublished } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );

			return {
				postId: editor.getCurrentPostId(),
				isPublished: editor.isCurrentPostPublished(),
			};
		}, [] );

		const { data, loading, error, busy, privacy, license } = useStoreState();

		useEffect( () => {
			if ( ! isPublished ) {
				store.loadedPostId = null;
			} else if ( postId && store.loadedPostId !== postId ) {
				load( postId );
			}
		}, [ postId, isPublished ] );

		const upload = ( videoId ) =>
			send( postId, videoId, {
				path: NAMESPACE + '/post/' + postId + '/youtube/upload',
				method: 'POST',
				data: { video_id: videoId, privacy: privacy, license: license },
			} );

		const retry = ( jobId ) => send( postId, jobId, { path: NAMESPACE + '/job/' + jobId + '/retry', method: 'POST' } );

		if ( ! isPublished ) {
			return el( 'p', null, __( 'Publish the post first: its public page is read to find the videos.', 'wp-scatter-elsewhere' ) );
		}

		if ( loading && ! data ) {
			return el( Spinner );
		}

		if ( ! data ) {
			return error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null;
		}

		if ( ! data.connected ) {
			return el(
				'p',
				null,
				__( 'The plugin is not connected to YouTube. ', 'wp-scatter-elsewhere' ),
				el( 'a', { href: data.settings_url }, __( 'Open the settings', 'wp-scatter-elsewhere' ) )
			);
		}

		if ( data.error ) {
			return el(
				'div',
				null,
				el( Notice, { status: 'warning', isDismissible: false }, data.error ),
				el( Button, { variant: 'secondary', onClick: () => load( postId ) }, __( 'Try again', 'wp-scatter-elsewhere' ) )
			);
		}

		if ( ! data.videos.length ) {
			return el( 'p', null, __( 'No video found in the page of this post.', 'wp-scatter-elsewhere' ) );
		}

		const canUpload = data.videos.some( ( video ) => video.uploadable && ! video.youtube && ! video.job );

		const children = [];

		if ( error ) {
			children.push( el( Notice, { key: 'error', status: 'error', onRemove: () => setState( { error: '' } ) }, error ) );
		}

		if ( canUpload ) {
			children.push(
				el( SelectControl, {
					key: 'privacy',
					label: __( 'Privacy', 'wp-scatter-elsewhere' ),
					value: privacy,
					options: PRIVACY_OPTIONS,
					onChange: ( value ) => setState( { privacy: value } ),
					__nextHasNoMarginBottom: true,
				} ),
				el( SelectControl, {
					key: 'license',
					label: __( 'License', 'wp-scatter-elsewhere' ),
					value: license,
					options: LICENSE_OPTIONS,
					onChange: ( value ) => setState( { license: value } ),
					__nextHasNoMarginBottom: true,
				} ),
				el( 'div', { key: 'spacer', style: { height: '12px' } } )
			);
		}

		data.videos.forEach( ( video ) => {
			children.push( el( VideoRow, { key: video.id, video: video, privacy: privacy, license: license, busy: busy, onUpload: upload, onRetry: retry } ) );
		} );

		return el( 'div', null, children );
	};

	registerPlugin( 'wp-scatter-elsewhere-youtube', {
		render: function () {
			return el(
				wp.element.Fragment,
				null,
				PluginPostPublishPanel
					? el( PluginPostPublishPanel, { name: 'wp-scatter-elsewhere-youtube-publish', title: __( 'YouTube', 'wp-scatter-elsewhere' ), initialOpen: true }, el( YouTubePanel ) )
					: null,
				PluginDocumentSettingPanel
					? el( PluginDocumentSettingPanel, { name: 'wp-scatter-elsewhere-youtube-settings', title: __( 'YouTube', 'wp-scatter-elsewhere' ), className: 'wp-scatter-elsewhere-youtube-panel' }, el( YouTubePanel ) )
					: null
			);
		},
	} );
}( window.wp ) );
