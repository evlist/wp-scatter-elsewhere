<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Matching;

use WP_Scatter_Elsewhere\Text\Folding;
use WP_Scatter_Elsewhere\YouTube\CatalogVideo;

/**
 * Finds the videos of a channel that probably belong to a post: the address of the post in the
 * description, the same title, or a similar title together with a close date.
 */
final class VideoMatcher {

	/** Days around the date of the post for a similar title. */
	public const DATE_TOLERANCE_DAYS = 3;

	/** Minimum similarity (percent) of two titles. */
	public const SIMILARITY = 80;

	public const MAX = 3;

	/**
	 * @param CatalogVideo[] $videos Videos that may be proposed (the ones already linked are left out by the caller).
	 * @return Suggestion[] Best first, at most MAX.
	 */
	public function match( PostFacts $post, array $videos ): array {
		$title  = Folding::fold( $post->title );
		$exact  = array_values( array_filter( $videos, static fn( CatalogVideo $v ): bool => '' !== $title && Folding::fold( $v->title ) === $title ) );
		$unique = 1 === count( $exact );
		$found  = [];

		foreach ( $videos as $video ) {
			$reasons = [];
			$score   = 0;

			if ( $this->holdsPermalink( $video->description, $post->permalink ) ) {
				$reasons[] = 'permalink';
				$score    += 100;
			}

			$folded = Folding::fold( $video->title );
			$close  = $this->isClose( $video, $post->timestamp );

			if ( '' !== $title && $folded === $title ) {
				$reasons[] = 'title';
				$score    += $unique ? 90 : 60;
			} elseif ( '' !== $title && '' !== $folded && $close && $this->similarity( $title, $folded ) >= self::SIMILARITY ) {
				$reasons[] = 'similar';
				$score    += 40;
			}

			if ( [] === $reasons ) {
				continue;
			}

			$score += $close ? 5 : 0;
			$high   = in_array( 'permalink', $reasons, true ) || ( in_array( 'title', $reasons, true ) && $unique );

			$found[] = new Suggestion( $video, $high ? Suggestion::HIGH : Suggestion::SUGGESTION, $reasons, $score );
		}

		usort( $found, static fn( Suggestion $a, Suggestion $b ): int => $b->score <=> $a->score );

		return array_slice( $found, 0, self::MAX );
	}

	/**
	 * Whether a description holds the address of the post, whatever the scheme, "www.", trailing slash,
	 * case or percent-encoding, and not the address of a longer one.
	 */
	private function holdsPermalink( string $description, string $permalink ): bool {
		$wanted = $this->address( $permalink );
		if ( '' === $wanted ) {
			return false;
		}

		return 1 === preg_match( '~' . preg_quote( $wanted, '~' ) . '(?![\p{L}\p{N}_-])~u', $this->decode( $description ) );
	}

	private function address( string $url ): string {
		$url = $this->decode( $url );
		$url = (string) preg_replace( '~^[a-z][a-z0-9+.-]*://(www\.)?~u', '', $url );

		return rtrim( $url, '/' );
	}

	private function decode( string $text ): string {
		return mb_strtolower( rawurldecode( $text ), 'UTF-8' );
	}

	/**
	 * The recording date of the video is compared first, then its upload date.
	 */
	private function isClose( CatalogVideo $video, int $timestamp ): bool {
		$date = null !== $video->recordingDate && '' !== $video->recordingDate ? $video->recordingDate : $video->publishedAt;
		$time = strtotime( $date );

		return false !== $time && abs( $time - $timestamp ) <= self::DATE_TOLERANCE_DAYS * 86400;
	}

	private function similarity( string $a, string $b ): float {
		similar_text( $a, $b, $percent );

		return $percent;
	}
}
