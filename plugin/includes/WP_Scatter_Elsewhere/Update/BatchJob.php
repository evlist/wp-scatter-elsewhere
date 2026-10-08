<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Update;

/**
 * A bulk update that runs in the background: its plan, the videos it goes through and where it stands.
 */
final class BatchJob {

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_PAUSED  = 'paused';
	public const STATUS_STOPPED = 'stopped';
	public const STATUS_DONE    = 'done';

	private const DEFAULTS = [
		'id'           => '',
		'plan'         => [],
		'status'       => self::STATUS_QUEUED,
		'targets'      => [],
		'cursor'       => 0,
		'changed'      => 0,
		'unchanged'    => 0,
		'errors'       => 0,
		'cost'         => 0,
		'message'      => '',
		'pause_until'  => 0,
		'locked_until' => 0,
		'stop'         => 0,
		'created_at'   => 0,
		'updated_at'   => 0,
	];

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct( private array $data ) {
	}

	/**
	 * @param array<string, mixed> $data Missing or mistyped keys take their default value.
	 */
	public static function fromArray( array $data ): self {
		$clean = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = $data[ $key ] ?? $default;

			if ( is_array( $default ) ) {
				$clean[ $key ] = is_array( $value ) ? $value : [];
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = (int) $value;
			} else {
				$clean[ $key ] = (string) $value;
			}
		}

		return new self( $clean );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function with( array $changes ): self {
		return self::fromArray( array_merge( $this->data, $changes ) );
	}

	public function id(): string {
		return $this->data['id'];
	}

	public function plan(): BatchPlan {
		return BatchPlan::fromArray( $this->data['plan'] );
	}

	public function status(): string {
		return $this->data['status'];
	}

	/**
	 * @return array<int, array{post: int, video: string, youtube: string}>
	 */
	public function targets(): array {
		return $this->data['targets'];
	}

	public function cursor(): int {
		return $this->data['cursor'];
	}

	public function total(): int {
		return count( $this->data['targets'] );
	}

	public function changed(): int {
		return $this->data['changed'];
	}

	public function unchanged(): int {
		return $this->data['unchanged'];
	}

	public function errors(): int {
		return $this->data['errors'];
	}

	public function cost(): int {
		return $this->data['cost'];
	}

	public function message(): string {
		return $this->data['message'];
	}

	public function pauseUntil(): int {
		return $this->data['pause_until'];
	}

	public function lockedUntil(): int {
		return $this->data['locked_until'];
	}

	public function stopRequested(): bool {
		return 0 !== $this->data['stop'];
	}

	public function createdAt(): int {
		return $this->data['created_at'];
	}

	/**
	 * Whether the job still has work to do or is waiting to do it.
	 */
	public function isActive(): bool {
		return in_array( $this->data['status'], [ self::STATUS_QUEUED, self::STATUS_PAUSED ], true );
	}
}
