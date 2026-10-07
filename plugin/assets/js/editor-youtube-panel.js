// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

( function ( wp ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { Button, Notice, SelectControl, Spinner, TextControl, Tooltip } = wp.components;
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

	// The privacy is the one requested when the video was uploaded: the plugin does not read it again from YouTube.
	const PRIVACY_HELP = __( 'Privacy set when the video was uploaded. It may have changed since, for example in YouTube Studio: the plugin does not check it.', 'wp-scatter-elsewhere' );

	const privacyInfo = function ( privacy, checkedAt ) {
		const label = privacyLabel( privacy );

		if ( ! label ) {
			return null;
		}

		const help = checkedAt
			? /* translators: %s: date and time. */ sprintf( __( 'Privacy read from YouTube on %s.', 'wp-scatter-elsewhere' ), new Date( checkedAt * 1000 ).toLocaleString() )
			: PRIVACY_HELP;

		return el(
			'span',
			null,
			' (' + label + ' ',
			el(
				Tooltip,
				{ text: help },
				el( 'span', { tabIndex: 0, role: 'img', 'aria-label': help, style: { cursor: 'help' } }, '\u24D8' )
			),
			')'
		);
	};

	const formatDate = function ( isoDate ) {
		const date = new Date( isoDate );

		return isNaN( date.getTime() ) ? '' : date.toLocaleDateString();
	};

	const errorMessage = function ( error ) {
		return error && error.message ? error.message : __( 'The request failed.', 'wp-scatter-elsewhere' );
	};

	/**
	 * One video of the post: its details, and its state or the controls to upload it.
	 */
	const VideoRow = function ( props ) {
		const { postId, video, privacy, license, busy, onUpload, onRetry, onCheck, onUnlink } = props;
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
					privacyInfo( video.youtube.privacy, video.youtube.checked_at ),
					' ',
					el(
						Button,
						{
							variant: 'link',
							isSmall: true,
							isBusy: busy === 'check:' + video.id,
							disabled: !! busy,
							onClick: () => onCheck( video.id ),
						},
						__( 'Check on YouTube', 'wp-scatter-elsewhere' )
					),
					' ',
					el(
						Button,
						{
							variant: 'link',
							isSmall: true,
							isDestructive: true,
							disabled: !! busy,
							onClick: () => onUnlink( video.id ),
						},
						__( 'Unlink', 'wp-scatter-elsewhere' )
					)
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

		if ( ! video.youtube && ! active ) {
			children.push( el( LinkForm, { key: 'link', postId: postId, videoId: video.id } ) );
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
	 * Links a video of the post to a video that is already on YouTube: the address is looked up, the video is
	 * shown for confirmation, and nothing is recorded before the author agrees.
	 */
	const LinkForm = function ( props ) {
		const { postId, videoId } = props;
		const [ open, setOpen ] = useState( false );
		const [ address, setAddress ] = useState( '' );
		const [ preview, setPreview ] = useState( null );
		const [ working, setWorking ] = useState( false );
		const [ failure, setFailure ] = useState( '' );
		const [ choosing, setChoosing ] = useState( false );
		const [ search, setSearch ] = useState( '' );
		const [ channel, setChannel ] = useState( null );

		const close = function () {
			setOpen( false );
			setAddress( '' );
			setPreview( null );
			setFailure( '' );
			setChoosing( false );
			setSearch( '' );
			setChannel( null );
		};

		// The videos of the channel, filtered by the words typed; "refresh" reads the channel again.
		const browse = function ( refresh ) {
			setWorking( true );
			setFailure( '' );

			apiFetch( { path: NAMESPACE + '/youtube/channel-videos?search=' + encodeURIComponent( search ) + ( refresh ? '&refresh=1' : '' ) } )
				.then( ( response ) => setChannel( response ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) )
				.finally( () => setWorking( false ) );
		};

		if ( ! open ) {
			return el( 'p', { style: { margin: '8px 0 0' } }, el( Button, { variant: 'link', onClick: () => setOpen( true ) }, __( 'Link a video that is already on YouTube', 'wp-scatter-elsewhere' ) ) );
		}

		const lookUp = function ( value ) {
			setWorking( true );
			setFailure( '' );
			setPreview( null );

			apiFetch( { path: NAMESPACE + '/post/' + postId + '/youtube/link-preview', method: 'POST', data: { video_id: videoId, address: value } } )
				.then( ( response ) => setPreview( response.preview ) )
				.catch( ( error ) => setFailure( errorMessage( error ) ) )
				.finally( () => setWorking( false ) );
		};

		const link = function () {
			setWorking( true );
			setFailure( '' );

			apiFetch( {
				path: NAMESPACE + '/post/' + postId + '/youtube/link',
				method: 'POST',
				data: { video_id: videoId, address: address, confirm_move: !! preview.linked_to },
			} )
				.then( ( response ) => {
					mergeStatuses( response.videos );
					close();
				} )
				.catch( ( error ) => {
					setFailure( errorMessage( error ) );
					setWorking( false );
				} );
		};

		const children = [
			el( TextControl, {
				key: 'address',
				label: __( 'YouTube address or video ID', 'wp-scatter-elsewhere' ),
				value: address,
				onChange: ( value ) => {
					setAddress( value );
					setPreview( null );
				},
				__nextHasNoMarginBottom: true,
			} ),
		];

		if ( failure ) {
			children.push( el( Notice, { key: 'failure', status: 'error', isDismissible: false }, failure ) );
		}

		if ( preview ) {
			const details = [ privacyLabel( preview.privacy ), formatDate( preview.published_at ) ].filter( Boolean ).join( ', ' );

			children.push(
				el( 'p', { key: 'found', style: { margin: '8px 0 4px', fontWeight: 600 } }, preview.title ),
				el( 'p', { key: 'details', style: { margin: '0 0 4px' } }, details ),
				preview.channel_checked ? null : el( 'p', { key: 'unchecked', style: { margin: '0 0 4px', color: '#757575' } }, __( 'The connected channel is not known, so the video could not be checked to belong to it.', 'wp-scatter-elsewhere' ) ),
				preview.linked_to
					? el(
						Notice,
						{ key: 'linked', status: 'warning', isDismissible: false },
						sprintf(
							/* translators: %s: title of another post. */
							__( 'This YouTube video is already linked to a video of "%s". Linking it here removes it from there.', 'wp-scatter-elsewhere' ),
							preview.linked_to.title
						),
						' ',
						preview.linked_to.edit_url ? el( 'a', { href: preview.linked_to.edit_url, target: '_blank', rel: 'noopener noreferrer' }, __( 'Open that post', 'wp-scatter-elsewhere' ) ) : null
					)
					: null,
				el(
					Button,
					{ key: 'confirm', variant: 'primary', isBusy: working, disabled: working, onClick: link },
					preview.linked_to ? __( 'Move the link here', 'wp-scatter-elsewhere' ) : __( 'Link this video', 'wp-scatter-elsewhere' )
				)
			);
		} else {
			children.push(
				el( Button, { key: 'lookup', variant: 'secondary', isBusy: working, disabled: working || '' === address.trim(), onClick: () => lookUp( address ) }, __( 'Look up', 'wp-scatter-elsewhere' ) )
			);
		}

		if ( ! preview ) {
			children.push( ' ', el( Button, { key: 'choose', variant: 'tertiary', disabled: working, onClick: () => { setChoosing( ! choosing ); setChannel( null ); } }, __( 'Choose from my channel', 'wp-scatter-elsewhere' ) ) );
		}

		if ( choosing && ! preview ) {
			const rows = ( channel ? channel.videos : [] ).map( ( item ) =>
				el(
					'li',
					{ key: item.youtube_id, style: { margin: '6px 0' } },
					el( 'strong', null, item.title ),
					el( 'div', { style: { color: '#757575' } }, [ privacyLabel( item.privacy ), formatDate( item.published_at ) ].filter( Boolean ).join( ', ' ) ),
					item.linked_to
						? el( 'div', { style: { color: '#757575' } }, sprintf(
							/* translators: %s: title of a post. */
							__( 'Already linked to "%s"', 'wp-scatter-elsewhere' ),
							item.linked_to.title
						) )
						: null,
					el( Button, { variant: 'link', disabled: working, onClick: () => { setAddress( item.youtube_id ); setChoosing( false ); lookUp( item.youtube_id ); } }, __( 'Choose', 'wp-scatter-elsewhere' ) )
				)
			);

			children.push(
				el(
					'div',
					{ key: 'channel', style: { marginTop: '8px' } },
					el( TextControl, { label: __( 'Words of the title', 'wp-scatter-elsewhere' ), value: search, onChange: setSearch, __nextHasNoMarginBottom: true } ),
					el( Button, { variant: 'secondary', isBusy: working, disabled: working, onClick: () => browse( false ) }, __( 'Search', 'wp-scatter-elsewhere' ) ),
					' ',
					el( Button, { variant: 'tertiary', disabled: working, onClick: () => browse( true ) }, __( 'Refresh from YouTube', 'wp-scatter-elsewhere' ) ),
					channel && 0 === channel.videos.length ? el( 'p', null, __( 'No video found.', 'wp-scatter-elsewhere' ) ) : null,
					channel && channel.videos.length ? el( 'ul', { style: { listStyle: 'none', margin: '8px 0 0', padding: 0 } }, rows ) : null,
					channel && channel.total > channel.videos.length ? el( 'p', { style: { color: '#757575' } }, sprintf(
						/* translators: 1: number shown, 2: number found. */
						__( '%1$d of %2$d videos shown: type more words to narrow the list.', 'wp-scatter-elsewhere' ),
						channel.videos.length,
						channel.total
					) ) : null
				)
			);
		}

		children.push( ' ', el( Button, { key: 'cancel', variant: 'tertiary', disabled: working, onClick: close }, __( 'Cancel', 'wp-scatter-elsewhere' ) ) );

		return el( 'div', { style: { marginTop: '8px' } }, children );
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

		const check = ( videoId ) =>
			send( postId, 'check:' + videoId, {
				path: NAMESPACE + '/post/' + postId + '/youtube/check',
				method: 'POST',
				data: { video_id: videoId },
			} );

		const unlink = ( videoId ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Remove the link to the YouTube video? Nothing is changed on YouTube.', 'wp-scatter-elsewhere' ) ) ) {
				return undefined;
			}

			return send( postId, 'unlink:' + videoId, {
				path: NAMESPACE + '/post/' + postId + '/youtube/unlink',
				method: 'POST',
				data: { video_id: videoId },
			} );
		};

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
			children.push( el( VideoRow, { key: video.id, video: video, privacy: privacy, license: license, busy: busy, postId: postId, onUpload: upload, onRetry: retry, onCheck: check, onUnlink: unlink } ) );
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
