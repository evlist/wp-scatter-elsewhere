<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Scatter_Everywhere\Detection\PageFetchException;
use WP_Scatter_Everywhere\Detection\PageFetcher;
use WP_Scatter_Everywhere\Detection\PostNotEligibleException;

class PageFetcherTest extends TestCase {

	private function post( array $overrides = [] ): array {
		return $overrides + [
			'permalink'          => 'https://example.org/post/',
			'status'             => 'publish',
			'password_protected' => false,
			'viewable'           => true,
		];
	}

	private function fetcher( ?array $post, callable $http, ?array &$requested = null ): PageFetcher {
		return new PageFetcher(
			static fn( int $id ): ?array => $post,
			static function ( string $url ) use ( $http, &$requested ): array {
				$requested[] = $url;

				return $http( $url );
			}
		);
	}

	public function test_fetches_only_the_permalink_of_the_post(): void {
		$requested = [];
		$page      = $this->fetcher( $this->post(), static fn(): array => [ 'status' => 200, 'body' => '<p>x</p>' ], $requested )->fetch( 7 );

		$this->assertSame( [ 'https://example.org/post/' ], $requested );
		$this->assertSame( 'https://example.org/post/', $page->url );
		$this->assertSame( '<p>x</p>', $page->html );
	}

	/**
	 * @dataProvider provideIneligiblePosts
	 */
	public function test_refuses_posts_that_are_not_public( ?array $post ): void {
		$requested = [];

		try {
			$this->fetcher( $post, static fn(): array => [ 'status' => 200, 'body' => 'x' ], $requested )->fetch( 7 );
			$this->fail( 'Expected a PostNotEligibleException.' );
		} catch ( PostNotEligibleException $e ) {
			$this->assertSame( [], $requested );
		}
	}

	/**
	 * @return array<string, array{?array}>
	 */
	public static function provideIneligiblePosts(): array {
		$post = ( new self( 'x' ) )->post();

		return [
			'missing'            => [ null ],
			'draft'              => [ array_merge( $post, [ 'status' => 'draft' ] ) ],
			'private'            => [ array_merge( $post, [ 'status' => 'private' ] ) ],
			'password protected' => [ array_merge( $post, [ 'password_protected' => true ] ) ],
			'not viewable'       => [ array_merge( $post, [ 'viewable' => false ] ) ],
		];
	}

	public function test_maps_transport_errors(): void {
		$fetcher = $this->fetcher( $this->post(), static function (): array {
			throw new RuntimeException( 'cURL error 7' );
		} );

		$this->expectException( PageFetchException::class );
		$this->expectExceptionMessage( 'cURL error 7' );

		$fetcher->fetch( 7 );
	}

	public function test_maps_http_errors_and_empty_pages(): void {
		foreach ( [ [ 'status' => 401, 'body' => 'x' ], [ 'status' => 503, 'body' => 'x' ], [ 'status' => 200, 'body' => "  \n" ] ] as $response ) {
			try {
				$this->fetcher( $this->post(), static fn(): array => $response )->fetch( 7 );
				$this->fail( 'Expected a PageFetchException.' );
			} catch ( PageFetchException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
