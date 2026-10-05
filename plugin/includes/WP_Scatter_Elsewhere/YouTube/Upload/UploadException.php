<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Scatter_Elsewhere\YouTube\Upload;

use RuntimeException;

/**
 * A job cannot be created or retried.
 */
final class UploadException extends RuntimeException {
}
