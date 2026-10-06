<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Elsewhere\Rules\TermRuleValidator;
use WP_Scatter_Elsewhere\Settings\TermRuleSettings;

class TermRuleSettingsTest extends TestCase {

	/** @var array<int, array<string, mixed>>|null */
	private ?array $saved = null;

	private function validator(): TermRuleValidator {
		return new TermRuleValidator( static fn( string $taxonomy, string $slug ): bool => in_array( $taxonomy . ':' . $slug, [ 'category:vanlife', 'category:velo', 'post_tag:salers' ], true ) );
	}

	private function settings( mixed $stored = [] ): TermRuleSettings {
		return new TermRuleSettings(
			static fn(): mixed => $stored,
			function ( array $value ): void {
				$this->saved = $value;
			}
		);
	}

	private static function row( string $taxonomy, string $term, string $playlist = '', string $keyword = '', bool $children = false, bool $remove = false ): array {
		return [ 'taxonomy' => $taxonomy, 'term' => $term, 'playlist_id' => $playlist, 'keyword' => $keyword, 'include_children' => $children, 'remove' => $remove ];
	}

	public function test_a_row_can_have_a_playlist_a_keyword_or_both(): void {
		$result = $this->validator()->validate(
			[
				self::row( 'category', 'vanlife', 'PLvanlife12345', 'vanlife', true ),
				self::row( 'post_tag', 'salers', '', ' Salers  ' ),
				self::row( 'category', 'velo', 'PLvelo1234567' ),
			]
		);

		$this->assertSame( [], $result['errors'] );
		$this->assertSame(
			[
				[ 'taxonomy' => 'category', 'term' => 'vanlife', 'playlist_id' => 'PLvanlife12345', 'keyword' => 'vanlife', 'include_children' => true ],
				[ 'taxonomy' => 'post_tag', 'term' => 'salers', 'playlist_id' => '', 'keyword' => 'Salers', 'include_children' => false ],
				[ 'taxonomy' => 'category', 'term' => 'velo', 'playlist_id' => 'PLvelo1234567', 'keyword' => '', 'include_children' => false ],
			],
			array_map( static fn( $rule ): array => $rule->toArray(), $result['rules'] )
		);
	}

	public function test_empty_and_removed_rows_are_ignored(): void {
		$result = $this->validator()->validate(
			[
				self::row( '', '' ),
				self::row( 'category', 'vanlife', 'PLvanlife12345', '', false, true ),
				self::row( 'category', 'velo', '', 'velo' ),
			]
		);

		$this->assertSame( [], $result['errors'] );
		$this->assertCount( 1, $result['rules'] );
		$this->assertSame( 'velo', $result['rules'][0]->term );
	}

	public function test_problems_are_reported_with_the_row_number(): void {
		$result = $this->validator()->validate(
			[
				self::row( 'category', 'vanlife', 'PLvanlife12345', 'a' ),
				self::row( 'category', 'vanlife', '', 'b' ),
				self::row( 'category', 'unknown', '', 'c' ),
				self::row( 'category', 'velo' ),
				self::row( 'post_tag', 'salers', 'bad id', '' ),
				self::row( '', '', '', 'orphan' ),
				self::row( 'post_tag', 'salers', '', '<>' ),
			]
		);

		$this->assertCount( 6, $result['errors'] );
		foreach ( [ 'Row 2:', 'Row 3:', 'Row 4:', 'Row 5:', 'Row 6:', 'Row 7:' ] as $index => $prefix ) {
			$this->assertStringStartsWith( $prefix, $result['errors'][ $index ] );
		}
	}

	public function test_nothing_is_saved_when_a_row_is_invalid(): void {
		try {
			$this->settings()->save( [ self::row( 'category', 'velo' ) ], $this->validator() );
			$this->fail( 'Expected an InvalidArgumentException.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Row 1:', $e->getMessage() );
			$this->assertNull( $this->saved );
		}
	}

	public function test_saves_and_reads_back_the_rules(): void {
		$this->settings()->save( [ self::row( 'category', 'vanlife', 'PLvanlife12345', 'vanlife', true ) ], $this->validator() );

		$rules = $this->settings( $this->saved )->rules();

		$this->assertCount( 1, $rules );
		$this->assertSame( 'PLvanlife12345', $rules[0]->playlistId );
		$this->assertTrue( $rules[0]->includeChildren );
	}

	public function test_malformed_stored_values_give_no_rules(): void {
		$this->assertSame( [], $this->settings( false )->rules() );
		$this->assertSame( [], $this->settings( 'garbage' )->rules() );
		$this->assertCount( 1, $this->settings( [ 'garbage', [ 'taxonomy' => 'category' ], [ 'taxonomy' => 'category', 'term' => 'velo', 'keyword' => 'velo' ] ] )->rules() );
	}
}
