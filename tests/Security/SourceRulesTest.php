<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_fabric, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_fabric
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

/*
 * Source rules from docs/PLAN.md "Code structure" and ADR 0004 that a parser
 * can check. The PHP 8.0 parse of the entry points themselves is proven by the
 * CI lint job on a real PHP 8.0; these checks catch the same mistakes earlier
 * and cover the rules a lint cannot.
 */

function fabric_root(): string {
	return dirname(__DIR__, 2);
}

/**
 * @return list<string> plugin PHP files outside tests/, relative to the root
 */
function fabric_sources(): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fabric_root(), FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(fabric_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/') && !str_starts_with($relative, '.git/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	return $files;
}

/**
 * @return list<string> files Cacti may include before the PHP version check
 */
function fabric_entry_points(): array {
	return array_values(array_filter(fabric_sources(), fn (string $f) => !str_starts_with($f, 'lib/')));
}

it('keeps every PHP source file within the gettext scan depth', function () {
	foreach (fabric_sources() as $file) {
		expect(substr_count($file, '/'))->toBeLessThanOrEqual(1, $file);
	}
});

it('keeps entry points free of PHP 8.1 and later syntax', function () {
	$banned = [T_ENUM => 'enum', T_READONLY => 'readonly'];

	foreach (fabric_entry_points() as $file) {
		$tokens = PhpToken::tokenize((string) file_get_contents(fabric_root() . '/' . $file));

		foreach ($tokens as $i => $token) {
			expect(isset($banned[$token->id]))->toBeFalse("$file:{$token->line} uses {$token->text}");

			// "never" as a return type and "new" in an initializer are 8.1.
			if ($token->is(T_STRING) && strtolower($token->text) === 'never') {
				$previous = $tokens[$i - 1]->is(T_WHITESPACE) ? $tokens[$i - 2] : $tokens[$i - 1];
				expect($previous->text)->not->toBe(':', "$file:{$token->line} uses never");
			}
		}
	}
});

it('uses no PHP 8.3 or later features anywhere', function () {
	foreach (fabric_sources() as $file) {
		$source = (string) file_get_contents(fabric_root() . '/' . $file);
		$tokens = PhpToken::tokenize($source);

		expect($source)->not->toContain('json_validate(')
			->and($source)->not->toContain('#[\\Override]')
			->and($source)->not->toContain('#[Override]');

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_CONST)) {
				continue;
			}

			// A typed constant has two identifiers between const and "=".
			$names = 0;

			for ($j = $i + 1; $j < count($tokens) && $tokens[$j]->text !== '='; $j++) {
				$names += $tokens[$j]->is(T_STRING) ? 1 : 0;
			}

			expect($names)->toBe(1, "$file:{$token->line} declares a typed constant");
		}
	}
});

it('defines exactly one class, interface, or enum per lib file, named after the file', function () {
	foreach (glob(fabric_root() . '/lib/*.php') as $path) {
		$tokens = PhpToken::tokenize((string) file_get_contents($path));
		$names  = [];

		foreach ($tokens as $i => $token) {
			if ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT]) && !$tokens[$i - 1]->is(T_DOUBLE_COLON)) {
				$next = $tokens[$i + 1]->is(T_WHITESPACE) ? $tokens[$i + 2] : $tokens[$i + 1];

				if ($next->is(T_STRING)) {
					$names[] = $next->text;
				}
			}
		}

		expect($names)->toBe([basename($path, '.php')], basename($path))
			->and((string) file_get_contents($path))->toContain("namespace Somethingwithproof\\Fabric;");
	}
});

it('keeps lib classes away from request variables and globals', function () {
	foreach (glob(fabric_root() . '/lib/*.php') as $path) {
		$source = (string) file_get_contents($path);

		expect($source)->not->toMatch('/\$_(REQUEST|GET|POST|SESSION|SERVER|COOKIE)\b/', basename($path))
			->and($source)->not->toMatch('/\bglobal\s+\$/', basename($path))
			->and($source)->not->toContain('$GLOBALS', basename($path));
	}
});

it('issues SQL only from repository and schema classes, through prepared helpers', function () {
	foreach (fabric_sources() as $file) {
		$source = (string) file_get_contents(fabric_root() . '/' . $file);

		expect($source)->not->toMatch('/\bdb_(execute|fetch_cell|fetch_row|fetch_assoc)\s*\(/', $file);

		// include/unbooted-uninstall.php is the one exception: it runs where
		// lib/ cannot load (see its header).
		$repository = str_starts_with($file, 'lib/') && preg_match('/^(Schema|\w+Repository)\.php$/', basename($file)) === 1;

		if (!$repository && $file !== 'include/unbooted-uninstall.php') {
			expect($source)->not->toMatch('/\bdb_\w+_prepared\s*\(/', $file);
		}
	}
});

it('passes the fabric text domain to every translated plugin string', function () {
	// Labels intentionally reused from core keep the core domain.
	$core = ['Management'];

	foreach (fabric_sources() as $file) {
		$tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents(fabric_root() . '/' . $file)), fn ($t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

		foreach ($tokens as $i => $token) {
			if (!$token->is(T_STRING) || $token->text !== '__' || ($tokens[$i + 1]->text ?? '') !== '(' || ($tokens[$i - 1]->is(T_FUNCTION))) {
				continue;
			}

			$depth = 0;
			$last  = null;

			for ($j = $i + 1; $j < count($tokens); $j++) {
				$depth += $tokens[$j]->text === '(' ? 1 : ($tokens[$j]->text === ')' ? -1 : 0);

				if ($depth === 0) {
					break;
				}

				if ($depth === 1 && $tokens[$j]->text !== ',' && $tokens[$j]->text !== '(') {
					$last = $tokens[$j];
				}
			}

			$first = $tokens[$i + 2];

			if ($first->is(T_CONSTANT_ENCAPSED_STRING) && in_array(trim($first->text, '\'"'), $core, true)) {
				continue;
			}

			expect($last?->text)->toBe("'fabric'", "$file:{$token->line}");
		}
	}
});

it('ships a translation template containing every plugin string', function () {
	$pot = (string) file_get_contents(fabric_root() . '/locales/po/cacti.pot');

	foreach (['The Fabric plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.'] as $msgid) {
		expect($pot)->toContain('msgid "' . $msgid . '"');
	}
});

it('formats translated strings with __() itself, never sprintf(__())', function () {
	// Core's __() hands the text domain to sprintf(), so a placeholder in
	// sprintf(__("%d", "fabric"), $n) takes "fabric" and prints 0 or the
	// domain. The values go inside the call: __("%d", $n, "fabric").
	foreach (fabric_sources() as $file) {
		expect((string) file_get_contents(fabric_root() . '/' . $file))->not->toContain('sprintf(__(', $file);
	}
});

it('uses plain placeholders in translated strings, because core\'s __() does not format positional ones', function () {
	// __() only formats a string that has %d, %s, and the like. A string with
	// only %1$d placeholders comes back raw.
	foreach (fabric_sources() as $file) {
		$source = (string) file_get_contents(fabric_root() . '/' . $file);

		preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $texts);

		foreach ($texts[1] as $text) {
			expect($text)->not->toMatch('/%[0-9]+\$/', $file);
		}
	}
});

it('logs through cacti_log() under the FABRIC tag only', function () {
	foreach (fabric_sources() as $file) {
		$code = '';

		foreach (PhpToken::tokenize((string) file_get_contents(fabric_root() . '/' . $file)) as $token) {
			$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
		}

		preg_match_all('/\bcacti_log\s*\((.*?)\);/s', $code, $calls);

		foreach ($calls[1] as $arguments) {
			expect(str_contains($arguments, "'FABRIC'"))->toBeTrue($file);
		}
	}
});

it('ships no Composer runtime dependencies', function () {
	$manifest = fabric_root() . '/composer.json';

	if (is_file($manifest)) {
		$require = json_decode((string) file_get_contents($manifest), true)['require'] ?? [];

		foreach (array_keys($require) as $package) {
			expect($package === 'php' || str_starts_with($package, 'ext-'))->toBeTrue($package);
		}
	}

	expect(is_dir(fabric_root() . '/vendor'))->toBeFalse();
});

/**
 * @return string the file without comments
 */
function fabric_code(string $file): string {
	$code = '';

	foreach (PhpToken::tokenize((string) file_get_contents(fabric_root() . '/' . $file)) as $token) {
		$code .= $token->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $token->text;
	}

	return $code;
}

it('starts processes only from ProcRunner, with an argument array, and never through a shell', function () {
	foreach (fabric_sources() as $file) {
		$code = fabric_code($file);

		expect($code)->not->toMatch('/\b(shell_exec|passthru|system|popen|pcntl_exec)\s*\(/', $file)
			->and($code)->not->toMatch('/(?<![\w>:$])exec\s*\(/', $file)
			->and($code)->not->toContain('`', $file);

		if ($file !== 'lib/ProcRunner.php') {
			expect($code)->not->toMatch('/\bproc_\w+\s*\(/', $file);
		}

		if ($file !== 'setup.php') {
			expect($code)->not->toMatch('/\bexec_background\s*\(/', $file);
		}
	}

	// proc_open() with an array command runs the program directly.
	expect(fabric_code('lib/ProcRunner.php'))->toMatch('/proc_open\(\$argv,/');
});

it('keeps pages away from sweeps and tools, so a page view never queries the fabric', function () {
	// REQ-SWEEP-005: only the script server path and fabric_sweep.php sweep.
	$pages = array_merge(Somethingwithproof\Fabric\Pages::files(), ['lib/AdminPage.php', 'lib/HealthPage.php', 'lib/InventoryPage.php', 'lib/FabricInput.php', 'lib/Format.php']);

	foreach ($pages as $file) {
		$code = '';

		foreach (PhpToken::tokenize(fabric_code($file)) as $token) {
			$code .= $token->is(T_CONSTANT_ENCAPSED_STRING) ? "''" : $token->text;
		}

		expect($code)->not->toMatch('/\b(Sweep|TopologySweep|CounterSweep|ToolRunner|ProcRunner|ProcessRunner|CommandBuilder|fabric_sweep)\b/', $file);
	}
});

it('builds the production process runner only in setup.php', function () {
	foreach (fabric_sources() as $file) {
		if ($file !== 'setup.php') {
			expect(fabric_code($file))->not->toMatch('/new\s+\\\\?(Somethingwithproof\\\\Fabric\\\\)?ProcRunner\b/', $file);
		}
	}
});

it('sends tool standard error to the log only as a cleaned excerpt, and prints no tool output', function () {
	foreach (fabric_sources() as $file) {
		preg_match_all('/\b(?:Log::\w+|cacti_log|print|echo|fwrite)\s*\(?(.*?)\);/s', fabric_code($file), $calls);

		foreach ($calls[1] as $arguments) {
			$arguments = str_replace('Log::excerpt($result->stderr)', '', $arguments);

			expect($arguments)->not->toMatch('/->(stdout|stderr)\b|\$output\b/', $file);
		}
	}
});

it('names no counter reset or clear option, no sudo, and no tool outside the allowlist in code', function () {
	// REQ-EXEC-003, REQ-EXEC-005, ADR 0009. Comments may name them; code may not.
	foreach (fabric_sources() as $file) {
		$code = fabric_code($file);

		expect($code)->not->toMatch("/'-(r|R|k|K|y)'|--reset_after_read|--Reset_only|--clear-errors|--clear-counts/", $file)
			->and($code)->not->toMatch('/\b(sudo|smpquery|ibdiagnet|ibqueryerrors|ibportstate|ibclearcounters|ibclearerrors|perfquery\s+-[rRkK])\b/', $file);
	}
});

it('stops every test PHP file outside tests/Unit unless it runs from the command line', function () {
	$files    = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fabric_root() . '/tests', FilesystemIterator::SKIP_DOTS));

	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen(fabric_root()) + 1);

		if ($file->getExtension() === 'php' && !str_starts_with($relative, 'tests/Unit/')) {
			$files[] = $relative;
		}
	}

	sort($files);

	expect($files)->toContain('tests/bin/lifecycle.php');

	foreach ($files as $file) {
		// The first statement after any namespace, declare, and use
		// statements must be the guard.
		$tokens = array_values(array_filter(token_get_all((string) file_get_contents(fabric_root() . '/' . $file)), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
		$code   = array_map(static fn ($t): string => is_array($t) ? $t[1] : $t, $tokens);
		$at     = 0;

		while (in_array(strtolower($code[$at] ?? ''), ['namespace', 'declare', 'use'], true)) {
			while (($code[$at] ?? ';') !== ';') {
				$at++;
			}

			$at++;
		}

		expect(implode('', array_slice($code, $at, 13)))->toBe("if(PHP_SAPI!=='cli'){exit(1);}", $file);
	}
});
