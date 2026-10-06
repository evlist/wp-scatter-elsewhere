<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube\Upload;

use Closure;
use RuntimeException;
use WP_Scatter_Elsewhere\YouTube\AccessTokenProvider;
use WP_Scatter_Elsewhere\YouTube\NotConnectedException;
use WP_Scatter_Elsewhere\YouTube\OAuthException;
use WP_Scatter_Elsewhere\YouTube\ReauthorizationRequiredException;

/**
 * Sends a video with the resumable upload protocol of the YouTube Data API.
 *
 * run() works for a limited time and returns the updated job: done, failed, in retry, or still
 * uploading when the time budget ran out. It does not raise for the failures it knows about.
 */
final class ResumableUploader {

	/** The parts of the video sent in the request body are appended to it, separated by commas. */
	public const INIT_ENDPOINT = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=';

	/** Chunks must be a multiple of 256 KiB, except the last one. */
	public const DEFAULT_CHUNK_SIZE = 8 * 1024 * 1024;

	public const MAX_ATTEMPTS = 12;

	private const MAX_BACKOFF_SECONDS  = 3600;
	private const FIRST_BACKOFF_SECONDS = 30;

	private const QUOTA_REASONS = [ 'quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded' ];

	/**
	 * @var Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string}
	 */
	private Closure $http;

	private AccessTokenProvider $tokens;

	/**
	 * @var Closure(string, int, int): (string|false)
	 */
	private Closure $reader;

	/**
	 * @var Closure(): int
	 */
	private Closure $clock;

	private int $chunkSize;

	/**
	 * @param Closure(string, string, array<string, string>, string): array{status: int, headers: array<string, string>, body: string} $http
	 *        Receives the method, URL, headers and raw body; returns the status, the lower-cased response headers
	 *        and the body; throws RuntimeException on transport errors.
	 * @param Closure(string, int, int): (string|false) $reader Reads $length bytes of a file from $offset.
	 * @param Closure(): int                            $clock  Current Unix time.
	 */
	public function __construct( Closure $http, AccessTokenProvider $tokens, Closure $reader, Closure $clock, int $chunkSize = self::DEFAULT_CHUNK_SIZE ) {
		$this->http      = $http;
		$this->tokens    = $tokens;
		$this->reader    = $reader;
		$this->clock     = $clock;
		$this->chunkSize = $chunkSize;
	}

	public function run( UploadJob $job, int $budgetSeconds ): UploadJob {
		$deadline  = ( $this->clock )() + $budgetSeconds;
		$needsSync = UploadJob::STATUS_RETRY === $job->status() && null !== $job->sessionUri();
		$job       = $job->with( [ 'status' => UploadJob::STATUS_UPLOADING ] );

		if ( $job->size() <= 0 ) {
			return $this->fail( $job, __( 'The video file is empty.', 'wp-scatter-elsewhere' ) );
		}

		while ( true ) {
			try {
				$token = $this->tokens->getAccessToken();
			} catch ( ReauthorizationRequiredException | NotConnectedException $e ) {
				return $this->fail( $job, $e->getMessage() );
			} catch ( OAuthException $e ) {
				return $this->retryLater( $job, $e->getMessage() );
			}

			if ( null === $job->sessionUri() ) {
				$job = $this->openSession( $job, $token );
				if ( UploadJob::STATUS_UPLOADING !== $job->status() ) {
					return $job;
				}
				$needsSync = false;
			}

			if ( $needsSync ) {
				$response  = $this->send( 'PUT', (string) $job->sessionUri(), $this->headers( $token, [ 'Content-Range' => 'bytes */' . $job->size() ] ), '' );
				$needsSync = false;
			} else {
				$prepared = $this->prepareChunk( $job, $token );
				if ( $prepared instanceof UploadJob ) {
					return $prepared;
				}
				$response = $this->send( 'PUT', (string) $job->sessionUri(), $prepared['headers'], $prepared['body'] );
			}

			$job = $this->interpret( $job, $response );

			if ( UploadJob::STATUS_UPLOADING !== $job->status() ) {
				return $job;
			}

			if ( ( $this->clock )() >= $deadline ) {
				return $job;
			}
		}
	}

	/**
	 * Opens the upload session. On failure, returns the failed or retrying job.
	 */
	private function openSession( UploadJob $job, string $token ): UploadJob {
		$snippet = [
			'title'       => $job->title(),
			'description' => $job->description(),
			'categoryId'  => $job->categoryId(),
		];
		if ( null !== $job->language() ) {
			$snippet['defaultLanguage']      = $job->language();
			$snippet['defaultAudioLanguage'] = $job->language();
		}

		$resource = [
			'snippet' => $snippet,
			'status'  => [
				'privacyStatus'           => $job->privacy(),
				'selfDeclaredMadeForKids' => false,
				'license'                 => $job->license(),
			],
		];
		$parts    = [ 'snippet', 'status' ];

		if ( null !== $job->recordingDate() ) {
			$resource['recordingDetails'] = [ 'recordingDate' => $job->recordingDate() ];
			$parts[]                      = 'recordingDetails';
		}

		// This class does not depend on WordPress, hence json_encode() rather than wp_json_encode().
		$body = (string) json_encode( $resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$response = $this->send(
			'POST',
			self::INIT_ENDPOINT . implode( ',', $parts ),
			$this->headers(
				$token,
				[
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Length' => (string) $job->size(),
					'X-Upload-Content-Type'   => $job->mimeType(),
				]
			),
			$body
		);

		if ( 200 !== $response['status'] ) {
			return $this->interpret( $job, $response );
		}

		$location = $response['headers']['location'] ?? '';
		if ( '' === $location ) {
			return $this->fail( $job, __( 'YouTube did not return an upload address.', 'wp-scatter-elsewhere' ) );
		}

		return $job->with( [ 'session_uri' => $location, 'bytes_sent' => 0 ] );
	}

	/**
	 * @return array{headers: array<string, string>, body: string}|UploadJob The request, or the failed job.
	 */
	private function prepareChunk( UploadJob $job, string $token ): array|UploadJob {
		$start  = $job->bytesSent();
		$length = min( $this->chunkSize, $job->size() - $start );

		if ( $length <= 0 ) {
			return $this->fail( $job, __( 'The upload position is inconsistent with the size of the file.', 'wp-scatter-elsewhere' ) );
		}

		$chunk = ( $this->reader )( $job->filePath(), $start, $length );
		if ( false === $chunk || strlen( $chunk ) !== $length ) {
			return $this->fail( $job, __( 'The video file cannot be read, or it changed during the upload.', 'wp-scatter-elsewhere' ) );
		}

		return [
			'headers' => $this->headers(
				$token,
				[
					'Content-Type'  => $job->mimeType(),
					'Content-Range' => sprintf( 'bytes %d-%d/%d', $start, $start + $length - 1, $job->size() ),
				]
			),
			'body'    => $chunk,
		];
	}

	/**
	 * Applies a response of YouTube to the job.
	 *
	 * @param array{status: int, headers: array<string, string>, body: string} $response
	 */
	private function interpret( UploadJob $job, array $response ): UploadJob {
		$status = $response['status'];

		if ( 200 === $status || 201 === $status ) {
			$data = json_decode( $response['body'], true );
			$id   = is_array( $data ) ? (string) ( $data['id'] ?? '' ) : '';

			if ( '' === $id ) {
				return $this->fail( $job, __( 'YouTube accepted the video but did not return its identifier.', 'wp-scatter-elsewhere' ) );
			}

			return $job->with(
				[
					'status'      => UploadJob::STATUS_DONE,
					'youtube_id'  => $id,
					'bytes_sent'  => $job->size(),
					'session_uri' => null,
					'error'       => null,
					'attempts'    => 0,
					'retry_at'    => 0,
				]
			);
		}

		if ( 308 === $status ) {
			return $job->with(
				[
					'status'     => UploadJob::STATUS_UPLOADING,
					'bytes_sent' => $this->receivedBytes( $response['headers']['range'] ?? '' ),
					'attempts'   => 0,
					'error'      => null,
				]
			);
		}

		if ( 404 === $status || 410 === $status ) {
			return $this->retryLater( $job->with( [ 'session_uri' => null, 'bytes_sent' => 0 ] ), __( 'The upload session has expired, the upload restarts.', 'wp-scatter-elsewhere' ), true );
		}

		$error = $this->describeError( $response );

		if ( 0 === $status || $status >= 500 || 429 === $status || 401 === $status || $this->isQuotaError( $response ) ) {
			return $this->retryLater( $job, $error );
		}

		return $this->fail( $job, $error );
	}

	/**
	 * Parses a "bytes=0-12345" header into the number of bytes received.
	 */
	private function receivedBytes( string $range ): int {
		if ( 1 === preg_match( '/^bytes=0-(\d+)$/', trim( $range ), $matches ) ) {
			return (int) $matches[1] + 1;
		}

		return 0;
	}

	/**
	 * @param array{status: int, headers: array<string, string>, body: string} $response
	 */
	private function isQuotaError( array $response ): bool {
		return 403 === $response['status'] && in_array( $this->errorReason( $response['body'] ), self::QUOTA_REASONS, true );
	}

	private function errorReason( string $body ): string {
		$data = json_decode( $body, true );

		return is_array( $data ) ? (string) ( $data['error']['errors'][0]['reason'] ?? '' ) : '';
	}

	/**
	 * @param array{status: int, headers: array<string, string>, body: string} $response
	 */
	private function describeError( array $response ): string {
		if ( 0 === $response['status'] ) {
			return sprintf(
				/* translators: %s: error returned by the HTTP layer. */
				__( 'The connection to YouTube failed: %s', 'wp-scatter-elsewhere' ),
				$response['body']
			);
		}

		$data    = json_decode( $response['body'], true );
		$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
		$reason  = $this->errorReason( $response['body'] );

		return sprintf(
			/* translators: 1: HTTP status code, 2: error reason code (may be empty), 3: error message (may be empty). */
			__( 'YouTube answered with HTTP status %1$d (%2$s). %3$s', 'wp-scatter-elsewhere' ),
			$response['status'],
			$reason,
			$message
		);
	}

	private function retryLater( UploadJob $job, string $error, bool $immediately = false ): UploadJob {
		$attempts = $job->attempts() + 1;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			return $this->fail(
				$job,
				sprintf(
					/* translators: 1: number of attempts, 2: last error message. */
					__( 'Giving up after %1$d attempts. Last error: %2$s', 'wp-scatter-elsewhere' ),
					$attempts,
					$error
				)
			);
		}

		$delay = min( self::MAX_BACKOFF_SECONDS, self::FIRST_BACKOFF_SECONDS * ( 2 ** ( $attempts - 1 ) ) );

		return $job->with(
			[
				'status'   => $immediately ? UploadJob::STATUS_UPLOADING : UploadJob::STATUS_RETRY,
				'attempts' => $attempts,
				'retry_at' => $immediately ? 0 : ( $this->clock )() + $delay,
				'error'    => $error,
			]
		);
	}

	private function fail( UploadJob $job, string $error ): UploadJob {
		return $job->with( [ 'status' => UploadJob::STATUS_FAILED, 'error' => $error ] );
	}

	/**
	 * @param array<string, string> $extra
	 * @return array<string, string>
	 */
	private function headers( string $token, array $extra ): array {
		return array_merge( [ 'Authorization' => 'Bearer ' . $token ], $extra );
	}

	/**
	 * Sends a request; a transport error becomes a response with status 0 and the error as body.
	 *
	 * @param array<string, string> $headers
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private function send( string $method, string $url, array $headers, string $body ): array {
		try {
			return ( $this->http )( $method, $url, $headers, $body );
		} catch ( RuntimeException $e ) {
			return [ 'status' => 0, 'headers' => [], 'body' => $e->getMessage() ];
		}
	}
}
