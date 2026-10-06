<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Subtitles;

/**
 * Converts WebVTT subtitles to SubViewer (SBV, the format of YouTube) or to SubRip (SRT).
 */
final class SubtitleConverter {

	/**
	 * SBV: "H:MM:SS.mmm,H:MM:SS.mmm" followed by the text, cues separated by a blank line, no numbering.
	 * SBV has no formatting, so every tag is removed.
	 *
	 * @return string The SBV text, with "\n" line endings; empty when the file holds no cue.
	 */
	public function vttToSbv( string $vtt ): string {
		$cues = [];
		foreach ( $this->cues( $vtt, false ) as $cue ) {
			$cues[] = $this->time( $cue['start'], false, '.' ) . ',' . $this->time( $cue['end'], false, '.' ) . "\n" . $cue['text'];
		}

		return [] === $cues ? '' : implode( "\n\n", $cues ) . "\n";
	}

	/**
	 * SRT: numbered cues, "HH:MM:SS,mmm --> HH:MM:SS,mmm", keeping the simple <i>, <b> and <u> tags.
	 *
	 * @return string The SubRip text, with "\n" line endings; empty when the file holds no cue.
	 */
	public function vttToSrt( string $vtt ): string {
		$cues = [];
		foreach ( $this->cues( $vtt, true ) as $cue ) {
			$cues[] = ( count( $cues ) + 1 ) . "\n" . $this->time( $cue['start'], true, ',' ) . ' --> ' . $this->time( $cue['end'], true, ',' ) . "\n" . $cue['text'];
		}

		return [] === $cues ? '' : implode( "\n\n", $cues ) . "\n";
	}

	/**
	 * The cues of a WebVTT file, without the header, notes, styles, regions and empty cues.
	 *
	 * @return array<int, array{start: array{int, int, int, string}, end: array{int, int, int, string}, text: string}>
	 */
	private function cues( string $vtt, bool $keepFormatting ): array {
		$vtt    = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $vtt );
		$vtt    = str_replace( [ "\r\n", "\r" ], "\n", $vtt );
		$blocks = preg_split( '/\n{2,}/', trim( $vtt ) ) ?: [];

		$cues = [];
		foreach ( $blocks as $block ) {
			$cue = $this->parseBlock( $block, $keepFormatting );
			if ( null !== $cue ) {
				$cues[] = $cue;
			}
		}

		return $cues;
	}

	/**
	 * @return array{start: array{int, int, int, string}, end: array{int, int, int, string}, text: string}|null
	 */
	private function parseBlock( string $block, bool $keepFormatting ): ?array {
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

		$text = $this->cleanText( implode( "\n", array_slice( $lines, $timingIndex + 1 ) ), $keepFormatting );
		if ( '' === $text ) {
			return null;
		}

		return [
			'start' => $this->parseTime( $matches[1] ),
			'end'   => $this->parseTime( $matches[2] ),
			'text'  => $text,
		];
	}

	/**
	 * "1:02.5" or "00:01:02.500" to hours, minutes, seconds and milliseconds (three digits).
	 *
	 * @return array{int, int, int, string}
	 */
	private function parseTime( string $time ): array {
		[ $clock, $fraction ] = preg_split( '/[.,]/', $time ) + [ 1 => '0' ];
		$parts                = array_map( 'intval', explode( ':', $clock ) );

		if ( 2 === count( $parts ) ) {
			array_unshift( $parts, 0 );
		}

		return [ $parts[0], $parts[1], $parts[2], str_pad( substr( $fraction, 0, 3 ), 3, '0' ) ];
	}

	/**
	 * @param array{int, int, int, string} $time
	 */
	private function time( array $time, bool $padHours, string $separator ): string {
		return sprintf( $padHours ? '%02d:%02d:%02d%s%s' : '%d:%02d:%02d%s%s', $time[0], $time[1], $time[2], $separator, $time[3] );
	}

	/**
	 * Removes the tags (keeping <i>, <b> and <u> when asked) and decodes the entities.
	 */
	private function cleanText( string $text, bool $keepFormatting ): string {
		// Tags are removed first, so a decoded "&lt;" is never taken for a tag.
		$text = (string) preg_replace_callback(
			'#</?([a-zA-Z][a-zA-Z0-9]*)(?:[.\s][^>]*)?>|<\d+:\d{2}(?::\d{2})?[.,]\d{1,3}>#',
			static fn( array $m ): string => $keepFormatting && isset( $m[1] ) && in_array( strtolower( $m[1] ), [ 'i', 'b', 'u' ], true ) ? '<' . ( str_starts_with( $m[0], '</' ) ? '/' : '' ) . strtolower( $m[1] ) . '>' : '',
			$text
		);

		$text = str_replace( [ '&lrm;', '&rlm;' ], '', $text );
		$text = str_replace( [ '&nbsp;', '&lt;', '&gt;', '&amp;' ], [ ' ', '<', '>', '&' ], $text );

		$lines = array_map( 'trim', explode( "\n", $text ) );

		return trim( implode( "\n", array_filter( $lines, static fn( string $line ): bool => '' !== $line ) ) );
	}
}
