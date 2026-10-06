<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Subtitles;

/**
 * Converts WebVTT subtitles to SubRip (SRT).
 */
final class SubtitleConverter {

	/**
	 * @return string The SubRip text, with "\n" line endings; empty when the file holds no cue.
	 */
	public function vttToSrt( string $vtt ): string {
		$vtt    = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $vtt );
		$vtt    = str_replace( [ "\r\n", "\r" ], "\n", $vtt );
		$blocks = preg_split( '/\n{2,}/', trim( $vtt ) ) ?: [];

		$cues = [];
		foreach ( $blocks as $block ) {
			$cue = $this->convertBlock( $block );
			if ( null !== $cue ) {
				$cues[] = ( count( $cues ) + 1 ) . "\n" . $cue;
			}
		}

		return [] === $cues ? '' : implode( "\n\n", $cues ) . "\n";
	}

	/**
	 * Returns "timing\ntext" for a cue block, or null for the header, notes, styles, regions and empty cues.
	 */
	private function convertBlock( string $block ): ?string {
		$lines = explode( "\n", $block );

		// A cue may start with an identifier line, which is dropped.
		$timingIndex = null;
		foreach ( $lines as $index => $line ) {
			if ( str_contains( $line, '-->' ) ) {
				$timingIndex = $index;
				break;
			}
			if ( $index >= 1 ) {
				break;
			}
		}

		if ( null === $timingIndex ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\s*((?:\d+:)?\d{1,2}:\d{2}[.,]\d{1,3})\s+-->\s+((?:\d+:)?\d{1,2}:\d{2}[.,]\d{1,3})/', $lines[ $timingIndex ], $matches ) ) {
			return null;
		}

		$text = $this->cleanText( implode( "\n", array_slice( $lines, $timingIndex + 1 ) ) );
		if ( '' === $text ) {
			return null;
		}

		return $this->srtTime( $matches[1] ) . ' --> ' . $this->srtTime( $matches[2] ) . "\n" . $text;
	}

	/**
	 * "1:02.5" or "00:01:02.500" to "00:01:02,500".
	 */
	private function srtTime( string $time ): string {
		[ $clock, $fraction ] = preg_split( '/[.,]/', $time ) + [ 1 => '0' ];
		$parts                = array_map( 'intval', explode( ':', $clock ) );

		if ( 2 === count( $parts ) ) {
			array_unshift( $parts, 0 );
		}

		return sprintf( '%02d:%02d:%02d,%s', $parts[0], $parts[1], $parts[2], str_pad( substr( $fraction, 0, 3 ), 3, '0' ) );
	}

	/**
	 * Keeps the simple <i>, <b> and <u> tags, removes the other tags and decodes the entities.
	 */
	private function cleanText( string $text ): string {
		// Tags are removed first, so a decoded "&lt;" is never taken for a tag.
		$text = (string) preg_replace_callback(
			'#</?([a-zA-Z][a-zA-Z0-9]*)(?:[.\s][^>]*)?>|<\d+:\d{2}(?::\d{2})?[.,]\d{1,3}>#',
			static fn( array $m ): string => isset( $m[1] ) && in_array( strtolower( $m[1] ), [ 'i', 'b', 'u' ], true ) ? '<' . ( str_starts_with( $m[0], '</' ) ? '/' : '' ) . strtolower( $m[1] ) . '>' : '',
			$text
		);

		$text = str_replace( [ '&lrm;', '&rlm;' ], '', $text );
		$text = str_replace( [ '&nbsp;', '&lt;', '&gt;', '&amp;' ], [ ' ', '<', '>', '&' ], $text );

		$lines = array_map( 'trim', explode( "\n", $text ) );

		return trim( implode( "\n", array_filter( $lines, static fn( string $line ): bool => '' !== $line ) ) );
	}
}
