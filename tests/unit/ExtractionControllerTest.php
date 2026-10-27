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

	/**
	 * Compressed tarballs must be recognised by every spelling users and
	 * Nextcloud produce, otherwise the inner tar is left behind as a file.
	 */
	public function testCompressedTarballsAreRecognised(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'isCompressedTarball');

		$tarballs = [
			'archive.tar',
			'archive.tar.gz',
			'archive.tar.bz2',
			'archive.tar.xz',
			'archive.tgz',
			'archive.tbz',
			'archive.tbz2',
			'archive.txz',
			'ARCHIVE.TGZ',
		];
		foreach ($tarballs as $name) {
			self::assertTrue($method->invoke(null, $name), "$name should be treated as a compressed tarball");
		}
	}

	public function testPlainArchivesAreNotTreatedAsCompressedTarballs(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'isCompressedTarball');

		foreach (['archive.zip', 'archive.7z', 'archive.rar', 'archive.deb', 'archive.gz'] as $name) {
			self::assertFalse($method->invoke(null, $name), "$name should not be treated as a compressed tarball");
		}
	}

	/**
	 * The frontend gates the action on its own copy of the archive MIME list, so
	 * the two lists must not drift apart.
	 */
	public function testTheFrontendAndBackendMimeListsAgree(): void {
		$action = file_get_contents(__DIR__ . '/../../src/actions/extract-action.ts');
		self::assertIsString($action);

		preg_match('/SUPPORTED_ARCHIVE_MIME_TYPES\s*=\s*\[(.*?)\]/s', $action, $matches);
		self::assertNotEmpty($matches, 'Could not find SUPPORTED_ARCHIVE_MIME_TYPES in the file action');

		preg_match_all("/'([^']+)'/", $matches[1], $frontendMatches);
		$frontend = $frontendMatches[1];
		self::assertNotEmpty($frontend, 'The frontend MIME list is empty');

		$backend = array_keys(ExtractionController::SUPPORTED_MIME_TYPES);

		sort($frontend);
		sort($backend);

		self::assertSame(
			$backend,
			$frontend,
			'The frontend and backend archive MIME lists have drifted apart',
		);
	}
}
