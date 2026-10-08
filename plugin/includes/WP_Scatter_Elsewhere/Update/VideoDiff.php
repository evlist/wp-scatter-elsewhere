<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

use WP_Scatter_Elsewhere\YouTube\VideoMetadata;

/**
 * Compares the video a post should give with the video on YouTube, for the fields that were asked for.
 */
final class VideoDiff {

	public const MODE_ADD  = 'add';
	public const MODE_SYNC = 'sync';

	/** Fields compared one against one. */
	public const SINGLE = [ 'title', 'description', 'language', 'license', 'recording_date', 'category', 'embeddable', 'public_stats', 'made_for_kids', 'privacy' ];

	/**
	 * @param string[]             $fields           Fields asked for.
	 * @param array<string, mixed> $snapshot         The video as read from YouTube (snippet, status, recordingDetails).
	 * @param string[]             $managedKeywords  Keywords named by the rules.
	 * @param ?string              $privacy          Privacy to apply when the field "privacy" is asked for.
	 * @return array{changes: Change[], skipped: string[]} "skipped" are the fields for which the post gives no value.
	 */
	public function compare( VideoMetadata $desired, array $snapshot, array $fields, string $keywordsMode, array $managedKeywords, ?string $privacy ): array {
		$changes = [];
		$skipped = [];

		foreach ( self::SINGLE as $field ) {
			if ( ! in_array( $field, $fields, true ) ) {
				continue;
			}

			$wanted = $this->wanted( $field, $desired, $privacy );
			if ( null === $wanted ) {
				$skipped[] = $field;
				continue;
			}

			$current = $this->current( $field, $snapshot );
			if ( $this->same( $field, $current, $wanted ) ) {
				continue;
			}

			$changes[] = new Change( $field, $this->show( $current ), $this->show( $wanted ), [ $field => $this->payloadValue( $field, $desired, $wanted ) ] );
		}

		if ( in_array( 'keywords', $fields, true ) ) {
			$change = $this->keywords( $desired->keywords, (array) ( $snapshot['snippet']['tags'] ?? [] ), self::MODE_SYNC === $keywordsMode, $managedKeywords );
			if ( null !== $change ) {
				$changes[] = $change;
			}
		}

		return [ 'changes' => $changes, 'skipped' => $skipped ];
	}

	private function wanted( string $field, VideoMetadata $desired, ?string $privacy ): string|bool|null {
		switch ( $field ) {
			case 'title':
				return '' === $desired->title ? null : $desired->title;
			case 'description':
				return $desired->description;
			case 'language':
				return null === $desired->language || '' === $desired->language ? null : $desired->language;
			case 'license':
				return $desired->license;
			case 'recording_date':
				return null === $desired->recordingDate ? null : substr( $desired->recordingDate, 0, 10 );
			case 'category':
				return $desired->categoryId;
			case 'embeddable':
				return $desired->embeddable;
			case 'public_stats':
				return $desired->publicStatsViewable;
			case 'made_for_kids':
				return $desired->madeForKids;
			default:
				return null === $privacy || '' === $privacy ? null : $privacy;
		}
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	private function current( string $field, array $snapshot ): string|bool|null {
		$snippet = (array) ( $snapshot['snippet'] ?? [] );
		$status  = (array) ( $snapshot['status'] ?? [] );

		switch ( $field ) {
			case 'title':
				return isset( $snippet['title'] ) ? (string) $snippet['title'] : null;
			case 'description':
				return (string) ( $snippet['description'] ?? '' );
			case 'language':
				return isset( $snippet['defaultLanguage'] ) ? (string) $snippet['defaultLanguage'] : null;
			case 'license':
				return isset( $status['license'] ) ? (string) $status['license'] : null;
			case 'recording_date':
				return isset( $snapshot['recordingDetails']['recordingDate'] ) ? substr( (string) $snapshot['recordingDetails']['recordingDate'], 0, 10 ) : null;
			case 'category':
				return isset( $snippet['categoryId'] ) ? (string) $snippet['categoryId'] : null;
			case 'embeddable':
				return (bool) ( $status['embeddable'] ?? true );
			case 'public_stats':
				return (bool) ( $status['publicStatsViewable'] ?? true );
			case 'made_for_kids':
				return (bool) ( $status['selfDeclaredMadeForKids'] ?? false );
			default:
				return isset( $status['privacyStatus'] ) ? (string) $status['privacyStatus'] : null;
		}
	}

	private function same( string $field, string|bool|null $current, string|bool $wanted ): bool {
		if ( 'description' === $field || 'title' === $field ) {
			return null !== $current && $this->text( (string) $current ) === $this->text( (string) $wanted );
		}

		return $current === $wanted;
	}

	private function text( string $text ): string {
		return trim( str_replace( "\r\n", "\n", $text ) );
	}

	private function payloadValue( string $field, VideoMetadata $desired, string|bool $wanted ): string|bool {
		// The recording date is compared by day but sent as the full date-time of the post.
		return 'recording_date' === $field ? (string) $desired->recordingDate : $wanted;
	}

	private function show( string|bool|null $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * @param string[] $wanted
	 * @param string[] $current
	 * @param string[] $managed
	 */
	private function keywords( array $wanted, array $current, bool $sync, array $managed ): ?Change {
		$lower = static fn( string $keyword ): string => mb_strtolower( $keyword, 'UTF-8' );
		$has   = array_map( $lower, $current );
		$want  = array_map( $lower, $wanted );
		$add   = array_values( array_filter( $wanted, static fn( string $keyword ): bool => ! in_array( $lower( $keyword ), $has, true ) ) );
		$remove = [];

		if ( $sync ) {
			$managedLower = array_map( $lower, $managed );
			$remove       = array_values( array_filter( $current, static fn( string $keyword ): bool => in_array( $lower( $keyword ), $managedLower, true ) && ! in_array( $lower( $keyword ), $want, true ) ) );
		}

		if ( [] === $add && [] === $remove ) {
			return null;
		}

		$payload = [];
		if ( [] !== $add ) {
			$payload['keywords'] = $add;
		}
		if ( [] !== $remove ) {
			$payload['keywords_remove'] = $remove;
		}

		$new = array_values( array_filter( $current, static fn( string $keyword ): bool => ! in_array( $keyword, $remove, true ) ) );

		return new Change( 'keywords', implode( ', ', $current ), implode( ', ', array_merge( $new, $add ) ), $payload );
	}
}
