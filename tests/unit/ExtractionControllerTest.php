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
	/**
	 * Every archive MIME Nextcloud reports is mapped.
	 */
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

	/**
	 * application/gzip uses the generic strategy.
	 */
	public function testGzipUsesTheGenericStrategy(): void {
		// Nextcloud reports application/gzip for .gz/.tgz; the controller used
		// to only know application/x-gzip, which made the action fail.
		self::assertSame('other', ExtractionController::SUPPORTED_MIME_TYPES['application/gzip']);
	}

	/**
	 * Zip and rar use their dedicated strategies.
	 */
	public function testZipAndRarUseTheirDedicatedStrategies(): void {
		self::assertSame('zip', ExtractionController::SUPPORTED_MIME_TYPES['application/zip']);
		self::assertSame('rar', ExtractionController::SUPPORTED_MIME_TYPES['application/x-rar-compressed']);
	}

	/**
	 * Every mapped strategy is one the controller implements.
	 */
	public function testEveryStrategyIsKnown(): void {
		foreach (ExtractionController::SUPPORTED_MIME_TYPES as $mime => $strategy) {
			self::assertContains($strategy, ['zip', 'rar', 'other'], "$mime maps to unknown strategy $strategy");
		}
	}

	/**
	 * Only tarballs with a real compression wrapper take the two-pass path.
	 *
	 * A plain `.tar` must not: 7za expands it in a single pass, so treating it as
	 * wrapped would make the controller look for a second archive inside the
	 * extracted payload.
	 */
	public function testWrappedTarballsAreRecognised(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'isWrappedTarball');

		$wrapped = [
			'archive.tar.gz',
			'archive.tar.bz2',
			'archive.tar.xz',
			'archive.tgz',
			'archive.tbz',
			'archive.tbz2',
			'archive.txz',
			'ARCHIVE.TGZ',
		];
		foreach ($wrapped as $name) {
			self::assertTrue($method->invoke(null, $name), "$name should take the two-pass path");
		}
	}

	/**
	 * Plain archives are not treated as wrapped tarballs.
	 */
	public function testPlainArchivesAreNotWrappedTarballs(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'isWrappedTarball');

		$plain = ['archive.tar', 'archive.zip', 'archive.7z', 'archive.rar', 'archive.deb', 'archive.gz'];
		foreach ($plain as $name) {
			self::assertFalse($method->invoke(null, $name), "$name should take the single-pass path");
		}
	}

	/**
	 * Compressed tarballs lose both suffixes so the result folder matches the
	 * one a plain archive of the same base name produces.
	 */
	public function testResultFolderNameDropsBothSuffixes(): void {
		$method = new \ReflectionMethod(ExtractionController::class, 'resultFolderName');

		$expected = [
			'archive.tar.gz' => 'archive',
			'archive.tgz' => 'archive',
			'archive.tar.bz2' => 'archive',
			'archive.tbz2' => 'archive',
			'archive.tar' => 'archive',
			'my.data.tar.gz' => 'my.data',
			'archive.zip' => 'archive',
			'archive.7z' => 'archive',
		];
		foreach ($expected as $name => $folder) {
			self::assertSame($folder, $method->invoke(null, $name), "wrong result folder for $name");
		}
	}

	/**
	 * A name that reduces to a path component must be rejected: the destination
	 * would otherwise be the containing or the parent directory, unpacking the
	 * archive outside the folder it lives in.
	 */
	public function testDegenerateArchiveNamesAreRejected(): void {
		$name = new \ReflectionMethod(ExtractionController::class, 'resultFolderName');
		$usable = new \ReflectionMethod(ExtractionController::class, 'isUsableFolderName');

		foreach (['.tar.gz', '.tar', '.tbz2', '.gz', '..tar.gz', '...tar.gz', '..', '.'] as $fileName) {
			self::assertFalse(
				$usable->invoke(null, $name->invoke(null, $fileName)),
				"$fileName must not be accepted as a result folder",
			);
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
