<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ChangedFiles;
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
    it('detects whitespace differences that may change __LINE__', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php \$foo   =   1;\n\necho   \$foo;");
        $b = ContentHash::ofContent('a.php', '<?php $foo=1; echo $foo;');

        expect($a)->not->toBe($b);
    });

    it('detects single-line comments that source tooling may inspect', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n// this is a comment\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->not->toBe($b);
    });

    it('detects hash-style comments conservatively', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n# hash comment\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->not->toBe($b);
    });

    it('detects multi-line comments conservatively', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n/* a multi\n line comment */\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->not->toBe($b);
    });

    it('detects reflection-visible PHPDoc annotations', function (): void {
        $a = ContentHash::ofContent('a.php', "<?php\n/**\n * @return int\n */\nfunction foo() { return 1; }");
        $b = ContentHash::ofContent('a.php', "<?php\nfunction foo() { return 1; }");

        expect($a)->not->toBe($b);
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

    it('does not hide different PHP bytes behind extension casing', function (): void {
        $a = ContentHash::ofContent('a.PHP', "<?php\n// comment\n\$foo = 1;");
        $b = ContentHash::ofContent('a.php', "<?php\n\$foo = 1;");

        expect($a)->not->toBe($b);
    });
});

describe('Blade files', function (): void {
    it('detects Blade comments in source fingerprints', function (): void {
        $a = ContentHash::ofContent('a.blade.php', '<div>{{-- a comment --}}Hello</div>');
        $b = ContentHash::ofContent('a.blade.php', '<div>Hello</div>');

        expect($a)->not->toBe($b);
    });

    it('detects multiline Blade content differences', function (): void {
        $a = ContentHash::ofContent('a.blade.php', "<div>\n{{--\n multi\n line\n--}}\nHello\n</div>");
        $b = ContentHash::ofContent('a.blade.php', '<div> Hello </div>');

        expect($a)->not->toBe($b);
    });

    it('detects whitespace changes in rendered or parsed source', function (): void {
        $a = ContentHash::ofContent('a.blade.php', "<div>\n  Hello\n  World\n</div>");
        $b = ContentHash::ofContent('a.blade.php', '<div> Hello World </div>');

        expect($a)->not->toBe($b);
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
    it('preserves JavaScript line comments in source fingerprints', function (): void {
        $a = ContentHash::ofContent('a.js', "// a comment\nconst foo = 1;");
        $b = ContentHash::ofContent('a.js', 'const foo = 1;');

        expect($a)->not->toBe($b);
    });

    it('preserves JavaScript block directives in fingerprints', function (): void {
        $a = ContentHash::ofContent('a.js', "/* block */\nconst foo = 1;");
        $b = ContentHash::ofContent('a.js', 'const foo = 1;');

        expect($a)->not->toBe($b);
    });

    it('detects whitespace changes in rendered or parsed source', function (): void {
        $a = ContentHash::ofContent('a.js', "const  foo  =  1;\n\nconst bar = 2;");
        $b = ContentHash::ofContent('a.js', 'const foo = 1; const bar = 2;');

        expect($a)->not->toBe($b);
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

    it('keeps TypeScript directives rather than discarding them', function (): void {
        $a = ContentHash::ofContent('a.ts', "// comment\nconst foo: number = 1;");
        $b = ContentHash::ofContent('a.ts', 'const foo: number = 1;');

        expect($a)->not->toBe($b);
    });

    it('keeps TSX source directives rather than discarding them', function (): void {
        $a = ContentHash::ofContent('a.tsx', "// comment\nconst Foo = () => <div/>;");
        $b = ContentHash::ofContent('a.tsx', 'const Foo = () => <div/>;');

        expect($a)->not->toBe($b);
    });

    it('keeps JSX source directives rather than discarding them', function (): void {
        $a = ContentHash::ofContent('a.jsx', "// comment\nconst Foo = () => <div/>;");
        $b = ContentHash::ofContent('a.jsx', 'const Foo = () => <div/>;');

        expect($a)->not->toBe($b);
    });

    it('keeps Vue source directives rather than discarding them', function (): void {
        $a = ContentHash::ofContent('a.vue', "<script>\n// comment\nexport default {}\n</script>");
        $b = ContentHash::ofContent('a.vue', '<script> export default {} </script>');

        expect($a)->not->toBe($b);
    });

    it('keeps Svelte source directives rather than discarding them', function (): void {
        $a = ContentHash::ofContent('a.svelte', "<script>\n// comment\nlet foo = 1;\n</script>");
        $b = ContentHash::ofContent('a.svelte', '<script> let foo = 1; </script>');

        expect($a)->not->toBe($b);
    });

    it('keeps module source directives rather than discarding them', function (): void {
        foreach (['mjs', 'cjs', 'mts'] as $ext) {
            $a = ContentHash::ofContent("a.$ext", "// comment\nexport const foo = 1;");
            $b = ContentHash::ofContent("a.$ext", 'export const foo = 1;');

            expect($a)->not->toBe($b);
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
    it('does not conflate reflection-visible PHPDoc roles', function (): void {
        $before = "<?php\n/** @permission admin */\nfunction accessPolicy() {}";
        $after = "<?php\n/** @permission guest */\nfunction accessPolicy() {}";

        expect(ContentHash::ofContent('app/Policy.php', $before))
            ->not->toBe(ContentHash::ofContent('app/Policy.php', $after));
    });

    it('does not conflate PHP line-number changes', function (): void {
        $before = "<?php\nreturn __LINE__;\n";
        $after = "<?php\n\nreturn __LINE__;\n";

        expect(ContentHash::ofContent('app/Caller.php', $before))
            ->not->toBe(ContentHash::ofContent('app/Caller.php', $after));
    });

    it('does not conflate rendered Blade spacing', function (): void {
        $before = '<p>Price:  100</p>';
        $after = '<p>Price: 100</p>';

        expect(ContentHash::ofContent('resources/views/checkout.blade.php', $before))
            ->not->toBe(ContentHash::ofContent('resources/views/checkout.blade.php', $after));
    });

    it('does not discard TypeScript check directives', function (): void {
        $before = "// @ts-check\nconst quantity = 1;";
        $after = 'const quantity = 1;';

        expect(ContentHash::ofContent('resources/js/checkout.ts', $before))
            ->not->toBe(ContentHash::ofContent('resources/js/checkout.ts', $after));
    });

    it('does not discard Vite bundler annotations', function (): void {
        $before = "/* @vite-ignore */\nimport(component);";
        $after = 'import(component);';

        expect(ContentHash::ofContent('resources/js/Pages/Orders.vue', $before))
            ->not->toBe(ContentHash::ofContent('resources/js/Pages/Orders.vue', $after));
    });

    it('retains observable edits through the last-run cache filter', function (): void {
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

            $changes = new ChangedFiles($root);
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
