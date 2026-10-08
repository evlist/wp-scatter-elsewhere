<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Quota;

/**
 * The quota units YouTube charges for each kind of request, identified from the verb and the address.
 */
final class CostTable {

	/** Units charged for each method of the API. */
	public const COSTS = [
		'videos.insert'        => 1600,
		'videos.update'        => 50,
		'videos.list'          => 1,
		'playlistItems.insert' => 50,
		'playlistItems.delete' => 50,
		'playlistItems.list'   => 1,
		'playlists.list'       => 1,
		'channels.list'        => 1,
		'captions.insert'      => 400,
		'captions.update'      => 450,
		'captions.delete'      => 50,
		'captions.list'        => 50,
		'thumbnails.set'       => 50,
	];

	/**
	 * @return array{method: string, units: int} The method (empty when the request costs nothing) and its cost.
	 */
	public static function identify( string $verb, string $url ): array {
		$verb = strtoupper( $verb );
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$query = (string) parse_url( $url, PHP_URL_QUERY );

		// The chunks of a resumable upload are sent to the session address, only opening it is charged.
		if ( str_contains( $query, 'upload_id=' ) ) {
			return [ 'method' => '', 'units' => 0 ];
		}

		$resource = null;
		if ( 1 === preg_match( '~/youtube/v3/([A-Za-z]+)(?:/set)?$~', $path, $matches ) ) {
			$resource = $matches[1];
		}

		if ( null === $resource ) {
			return [ 'method' => '', 'units' => 0 ];
		}

		$name = self::method( $resource, $verb );

		return [ 'method' => $name, 'units' => self::COSTS[ $name ] ?? 1 ];
	}

	private static function method( string $resource, string $verb ): string {
		if ( 'thumbnails' === $resource ) {
			return 'thumbnails.set';
		}

		switch ( $verb ) {
			case 'POST':
				return $resource . '.insert';
			case 'PUT':
				return $resource . '.update';
			case 'DELETE':
				return $resource . '.delete';
			default:
				return $resource . '.list';
		}
	}
}
