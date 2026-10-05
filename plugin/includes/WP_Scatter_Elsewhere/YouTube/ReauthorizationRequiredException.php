<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube;

/**
 * Google refused the refresh token: the administrator has to authorise the plugin again.
 */
final class ReauthorizationRequiredException extends YouTubeConnectionException {
}
