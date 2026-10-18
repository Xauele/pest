<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ContentHash;

describe('of()', function (): void {
    it('returns false when file does not exist', function (): void {
        expect(ContentHash::of('/path/that/does/not/exist.php'))->toBeFalse();
    });

    it('hashes an existing file', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'pest_').'.php';
        file_put_contents($path, "<?php echo 'hi';");

        try {
            expect(ContentHash::of($path))->toBeString()->not->toBeEmpty();
        } finally {
            @unlink($path);
        }
    });
});

describe('PHP files', function (): void {
    it('produces the same hash regardless of whitespace differences', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php \$foo   =   1;\n\necho   \$foo;");
        $b = ContentHash::ofContent('a.php', '<?php $foo=1; echo $foo;');

        expect($a)->toBe($b);
    });

    it('ignores single-line comments', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n// this is a comment\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->toBe($b);
    });

    it('ignores hash-style comments', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n# hash comment\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->toBe($b);
    });

    it('ignores multi-line comments', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n/* a multi\n line comment */\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->toBe($b);
    });

    it('ignores doc comments', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n/**\n * @return int\n */\nfunction foo() { return 1; }");
        $b = ContentHash::ofContent('a.php', "<?php\nfunction foo() { return 1; }");

        expect($a)->toBe($b);
    });

    it('detects code changes', function (): void {
        $a = ContentHash::ofContent('a.php', '<?php $foo = 1;');
        $b = ContentHash::ofContent('a.php', '<?php $foo = 2;');

        expect($a)->not->toBe($b);
    });

    it('preserves whitespace inside string literals', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php \$foo = 'hello world';");
        $b = ContentHash::ofContent('a.php', "<?php \$foo = 'helloworld';");

        expect($a)->not->toBe($b);
    });

    it('treats variable renames as a change', function (): void {
        $a = ContentHash::ofContent('a.php', '<?php $foo = 1;');
        $b = ContentHash::ofContent('a.php', '<?php $bar = 1;');

        expect($a)->not->toBe($b);
    });

    it('falls back to a raw hash for unparseable PHP', function (): void {
        $hash = ContentHash::ofContent('a.php', 'not valid php at all');

        expect($hash)->toBeString()->not->toBeEmpty();
    });

    it('is case-insensitive on the file extension', function (): void {
        $a = ContentHash::ofContent('a.PHP', "<?php\n// comment\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->toBe($b);
    });
});

describe('Blade files', function (): void {
    it('strips blade comments', function (): void {
        $a = ContentHash::ofContent('a.blade.php', '<div>{{-- a comment --}}Hello</div>');
        $b = ContentHash::ofContent('a.blade.php', '<div>Hello</div>');

        expect($a)->toBe($b);
    });

    it('strips multi-line blade comments', function (): void {
        $a = ContentHash::ofContent('a.blade.php', "<div>\n{{--\n multi\n line\n--}}\nHello\n</div>");
        $b = ContentHash::ofContent('a.blade.php', '<div> Hello </div>');

        expect($a)->toBe($b);
    });

    it('collapses whitespace', function (): void {
        $a = ContentHash::ofContent('a.blade.php', "<div>\n  Hello\n  World\n</div>");
        $b = ContentHash::ofContent('a.blade.php', '<div> Hello World </div>');

        expect($a)->toBe($b);
    });

    it('detects content changes', function (): void {
        $a = ContentHash::ofContent('a.blade.php', '<div>Hello</div>');
        $b = ContentHash::ofContent('a.blade.php', '<div>Goodbye</div>');

        expect($a)->not->toBe($b);
    });

    it('keeps blade directives intact', function (): void {
        $a = ContentHash::ofContent('a.blade.php', '@if($user)Hi @endif');
        $b = ContentHash::ofContent('a.blade.php', '@if($user)Bye @endif');

        expect($a)->not->toBe($b);
    });

    it('does not use the PHP tokenizer for blade files', function (): void {
        $a = ContentHash::ofContent('a.blade.php', '<?php // not stripped ?> hello');
        $b = ContentHash::ofContent('a.blade.php', '<?php ?> hello');

        expect($a)->not->toBe($b);
    });
});

describe('JavaScript-like files', function (): void {
    it('strips line comments', function (): void {
        $a = ContentHash::ofContent('a.js', "// a comment\nconst foo = 1;");
        $b = ContentHash::ofContent('a.js', 'const foo = 1;');

        expect($a)->toBe($b);
    });

    it('strips block comments on their own lines', function (): void {
        $a = ContentHash::ofContent('a.js', "/* block */\nconst foo = 1;");
        $b = ContentHash::ofContent('a.js', 'const foo = 1;');

        expect($a)->toBe($b);
    });

    it('collapses whitespace', function (): void {
        $a = ContentHash::ofContent('a.js', "const  foo  =  1;\n\nconst bar = 2;");
        $b = ContentHash::ofContent('a.js', 'const foo = 1; const bar = 2;');

        expect($a)->toBe($b);
    });

    it('detects code changes', function (): void {
        $a = ContentHash::ofContent('a.js', 'const foo = 1;');
        $b = ContentHash::ofContent('a.js', 'const foo = 2;');

        expect($a)->not->toBe($b);
    });

    it('does not strip inline trailing comments', function (): void {
        $a = ContentHash::ofContent('a.js', 'const foo = 1; // inline');
        $b = ContentHash::ofContent('a.js', 'const foo = 1;');

        expect($a)->not->toBe($b);
    });

    it('applies the same rules to .ts files', function (): void {
        $a = ContentHash::ofContent('a.ts', "// comment\nconst foo: number = 1;");
        $b = ContentHash::ofContent('a.ts', 'const foo: number = 1;');

        expect($a)->toBe($b);
    });

    it('applies the same rules to .tsx files', function (): void {
        $a = ContentHash::ofContent('a.tsx', "// comment\nconst Foo = () => <div/>;");
        $b = ContentHash::ofContent('a.tsx', 'const Foo = () => <div/>;');

        expect($a)->toBe($b);
    });

    it('applies the same rules to .jsx files', function (): void {
        $a = ContentHash::ofContent('a.jsx', "// comment\nconst Foo = () => <div/>;");
        $b = ContentHash::ofContent('a.jsx', 'const Foo = () => <div/>;');

        expect($a)->toBe($b);
    });

    it('applies the same rules to .vue files', function (): void {
        $a = ContentHash::ofContent('a.vue', "<script>\n// comment\nexport default {}\n</script>");
        $b = ContentHash::ofContent('a.vue', '<script> export default {} </script>');

        expect($a)->toBe($b);
    });

    it('applies the same rules to .svelte files', function (): void {
        $a = ContentHash::ofContent('a.svelte', "<script>\n// comment\nlet foo = 1;\n</script>");
        $b = ContentHash::ofContent('a.svelte', '<script> let foo = 1; </script>');

        expect($a)->toBe($b);
    });

    it('applies the same rules to .mjs, .cjs, and .mts files', function (): void {
        foreach (['mjs', 'cjs', 'mts'] as $ext) {
            $a = ContentHash::ofContent("a.$ext", "// comment\nexport const foo = 1;");
            $b = ContentHash::ofContent("a.$ext", 'export const foo = 1;');

            expect($a)->toBe($b);
        }
    });
});

describe('unknown extensions', function (): void {
    it('hashes the raw content for unknown extensions', function (): void {
        $a = ContentHash::ofContent('a.txt', 'hello world');
        $b = ContentHash::ofContent('a.txt', 'hello world');

        expect($a)->toBe($b);
    });

    it('does not normalise whitespace for unknown extensions', function (): void {
        $a = ContentHash::ofContent('a.txt', 'hello  world');
        $b = ContentHash::ofContent('a.txt', 'hello world');

        expect($a)->not->toBe($b);
    });

    it('does not strip comments for unknown extensions', function (): void {
        $a = ContentHash::ofContent('a.txt', "// not a comment here\nhello");
        $b = ContentHash::ofContent('a.txt', 'hello');

        expect($a)->not->toBe($b);
    });

    it('hashes files with no extension as raw content', function (): void {
        $a = ContentHash::ofContent('Makefile', "all:\n\techo hi");
        $b = ContentHash::ofContent('Makefile', "all:\n\techo hi");

        expect($a)->toBe($b);
    });
});

describe('output format', function (): void {
    it('returns a 32-character hex xxh128 hash', function (): void {
        $hash = ContentHash::ofContent('a.php', '<?php $foo = 1;');

        expect($hash)->toMatch('/^[a-f0-9]{32}$/');
    });

    it('returns a stable hash for empty content', function (): void {
        $a = ContentHash::ofContent('a.php', '');
        $b = ContentHash::ofContent('a.php', '');

        expect($a)->toBe($b);
    });
});

describe('observable source semantics', function (): void {
    it('observable PHPDoc annotations cannot share the same TIA fingerprint', function (): void {
        $before = "<?php\n/** @permission admin */\nfunction accessPolicy() {}";
        $after = "<?php\n/** @permission guest */\nfunction accessPolicy() {}";

        expect(ContentHash::ofContent('app/Policy.php', $before))
            ->not->toBe(ContentHash::ofContent('app/Policy.php', $after));
    });

    it('observable PHP line numbers cannot share the same TIA fingerprint', function (): void {
        $before = "<?php\nreturn __LINE__;\n";
        $after = "<?php\n\nreturn __LINE__;\n";

        expect(ContentHash::ofContent('app/Caller.php', $before))
            ->not->toBe(ContentHash::ofContent('app/Caller.php', $after));
    });

    it('observable Blade text whitespace cannot share the same TIA fingerprint', function (): void {
        $before = '<p>Price:  100</p>';
        $after = '<p>Price: 100</p>';

        expect(ContentHash::ofContent('resources/views/checkout.blade.php', $before))
            ->not->toBe(ContentHash::ofContent('resources/views/checkout.blade.php', $after));
    });

    it('observable TypeScript directives cannot share the same TIA fingerprint', function (): void {
        $before = "// @ts-check\nconst quantity = 1;";
        $after = 'const quantity = 1;';

        expect(ContentHash::ofContent('resources/js/checkout.ts', $before))
            ->not->toBe(ContentHash::ofContent('resources/js/checkout.ts', $after));
    });

    it('observable Vite source pragmas cannot share the same TIA fingerprint', function (): void {
        $before = "/* @vite-ignore */\nimport(component);";
        $after = 'import(component);';

        expect(ContentHash::ofContent('resources/js/Pages/Orders.vue', $before))
            ->not->toBe(ContentHash::ofContent('resources/js/Pages/Orders.vue', $after));
    });

    it('observable source changes survive last-run tree filtering', function (): void {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-tia-observable-'.bin2hex(random_bytes(8));
        mkdir($root.'/app', 0755, true);
        $path = $root.'/app/Permission.php';
        $before = "<?php\n/** @permission admin */\nclass Permission {}";
        $after = "<?php\n/** @permission guest */\nclass Permission {}";

        try {
            file_put_contents($path, $before);
            $previous = ContentHash::of($path);
            expect($previous)->toBeString();

            file_put_contents($path, $after);

            $changes = new \Pest\Plugins\Tia\ChangedFiles($root);
            expect($changes->filterUnchangedSinceLastRun(
                ['app/Permission.php'],
                ['app/Permission.php' => $previous],
            ))->toBe(['app/Permission.php']);
        } finally {
            @unlink($path);
            @rmdir($root.'/app');
            @rmdir($root);
        }
    });
});
