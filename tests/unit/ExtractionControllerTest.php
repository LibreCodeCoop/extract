<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Controller\ExtractionController;
use PHPUnit\Framework\TestCase;

/**
 * Guards the mapping between the archive MIME types Nextcloud reports and the
 * extraction strategy the controller selects.
 */
final class ExtractionControllerTest extends TestCase {
	public function testEverySupportedExtensionHasAMimeType(): void {
		// The MIME types Nextcloud resolves for the extensions listed in the
		// README (see resources/config/mimetypemapping.dist.json in the server).
		$nextcloudArchiveMimeTypes = [
			'application/zip', // .zip
			'application/x-rar-compressed', // .rar
			'application/x-tar', // .tar
			'application/gzip', // .gz and .tgz
			'application/x-bzip2', // .bz2 and .tbz2
			'application/x-7z-compressed', // .7z
			'application/x-deb', // .deb
		];

		foreach ($nextcloudArchiveMimeTypes as $mime) {
			self::assertArrayHasKey(
				$mime,
				ExtractionController::SUPPORTED_MIME_TYPES,
				"$mime is not mapped to an extraction strategy",
			);
		}
	}

	public function testGzipUsesTheGenericStrategy(): void {
		// Nextcloud reports application/gzip for .gz/.tgz; the controller used
		// to only know application/x-gzip, which made the action fail.
		self::assertSame('other', ExtractionController::SUPPORTED_MIME_TYPES['application/gzip']);
	}

	public function testZipAndRarUseTheirDedicatedStrategies(): void {
		self::assertSame('zip', ExtractionController::SUPPORTED_MIME_TYPES['application/zip']);
		self::assertSame('rar', ExtractionController::SUPPORTED_MIME_TYPES['application/x-rar-compressed']);
	}

	public function testEveryStrategyIsKnown(): void {
		foreach (ExtractionController::SUPPORTED_MIME_TYPES as $mime => $strategy) {
			self::assertContains($strategy, ['zip', 'rar', 'other'], "$mime maps to unknown strategy $strategy");
		}
	}
}
