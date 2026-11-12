<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Hooks {
	/**
	 * Stub of OC\Hooks\Emitter for static analysis and unit tests.
	 *
	 * The OCP interfaces (for example OCP\Files\IRootFolder) extend this type, so
	 * it has to exist whenever they are loaded outside a full Nextcloud checkout.
	 *
	 * It must be declared as an interface to match the real definition in
	 * lib/private/Hooks/Emitter.php. Declaring it as a class makes PHP refuse to
	 * load the OCP interfaces with "cannot implement OC\Hooks\Emitter - it is not
	 * an interface".
	 */
	interface Emitter {
		public function listen($scope, $method, callable $callback);

		public function removeListener($scope = null, $method = null, ?callable $callback = null);
	}
}
