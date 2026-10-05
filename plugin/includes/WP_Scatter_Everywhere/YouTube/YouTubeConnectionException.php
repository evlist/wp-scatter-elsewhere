<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Everywhere\YouTube;

use RuntimeException;

/**
 * Base class of the errors raised while connecting to YouTube or obtaining an access token.
 */
class YouTubeConnectionException extends RuntimeException {
}
