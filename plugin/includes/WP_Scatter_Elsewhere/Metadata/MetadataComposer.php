<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\Metadata;

use WP_Scatter_Elsewhere\Settings\MetadataTemplateSettings;

/**
 * Turns a post into the title and description sent to YouTube.
 */
final class MetadataComposer {

	private MetadataTemplateSettings $settings;

	private TemplateRenderer $renderer;

	private YouTubeTextNormalizer $normalizer;

	public function __construct( MetadataTemplateSettings $settings, TemplateRenderer $renderer, YouTubeTextNormalizer $normalizer ) {
		$this->settings   = $settings;
		$this->renderer   = $renderer;
		$this->normalizer = $normalizer;
	}

	/**
	 * @return array{title: string, description: string}
	 */
	public function compose( PostData $post ): array {
		$postTitle = $this->renderer->render( MetadataTemplateSettings::DEFAULT_TITLE, $post );

		return [
			'title'       => $this->normalizer->normalizeTitle(
				$this->renderer->render( $this->settings->getTitleTemplate(), $post ),
				$postTitle
			),
			'description' => $this->normalizer->normalizeDescription(
				$this->renderer->render( $this->settings->getDescriptionTemplate(), $post )
			),
		];
	}
}
