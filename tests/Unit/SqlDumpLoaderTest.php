<?php

use App\Import\IncompleteStatement;
use App\Import\SqlDumpLoader;

it('parses mysqldump extended inserts with escapes, NULLs, numbers and introducers', function () {
    [$columns, $rows] = (new SqlDumpLoader([]))->parseInsert(
        "INSERT INTO `t` VALUES (1,'it\\'s','a\\\\b\\nc',NULL,-2.5,'{\\\"k\\\": \\\"v\\\"}',_binary 'bin',0x4142,'semi;colon','paren)'),(2,'','x''y',TRUE,1e3,'',_utf8mb4'u',0x,'',NULL);"
    );

    expect($columns)->toBeNull()
        ->and($rows)->toBe([
            ['1', "it's", "a\\b\nc", null, '-2.5', '{"k": "v"}', 'bin', 'AB', 'semi;colon', 'paren)'],
            ['2', '', "x'y", '1', '1e3', '', 'u', '', '', null],
        ]);
});

it('reads explicit column lists and asks for more input when a statement is cut', function () {
    $loader = new SqlDumpLoader([]);

    [$columns, $rows] = $loader->parseInsert("INSERT IGNORE INTO `t` (`id`, `name`) VALUES\n(1, 'a'),\n(2, 'b');");
    expect($columns)->toBe(['id', 'name'])->and($rows)->toBe([['1', 'a'], ['2', 'b']]);

    expect(fn () => $loader->parseInsert("INSERT INTO `t` (`id`, `name`) VALUES\n(1, 'a'),\n"))->toThrow(IncompleteStatement::class);
    expect(fn () => $loader->parseInsert("INSERT INTO `t` VALUES (1,'unterminated"))->toThrow(IncompleteStatement::class);
});

it('loads only the wanted tables from a gzipped dump with one tuple per line', function () {
    $dir = sys_get_temp_dir().'/dump-test-'.uniqid();
    mkdir($dir);
    $dump = $dir.'/dump.sql.gz';
    $sqlite = $dir.'/out.sqlite';

    file_put_contents($dump, gzencode(implode("\n", [
        'CREATE TABLE `keep` (',
        '  `id` int NOT NULL,',
        '  `note` text,',
        '  PRIMARY KEY (`id`)',
        ') ENGINE=InnoDB;',
        'CREATE TABLE `skip` (`id` int);',
        'INSERT INTO `skip` VALUES (1);',
        'INSERT INTO `keep` (`id`, `note`) VALUES',
        "(1, 'first'),",
        "(2, 'multi\\nline');",
        'INSERT INTO `keep` VALUES (3,NULL);',
        '',
    ])));

    $counts = (new SqlDumpLoader(['keep']))->load($dump, $sqlite);
    $rows = (new PDO('sqlite:'.$sqlite))->query('select id, note from keep order by id')->fetchAll(PDO::FETCH_NUM);
    $tables = (new PDO('sqlite:'.$sqlite))->query("select name from sqlite_master where type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

    expect($counts)->toBe(['keep' => 3])
        ->and($rows)->toBe([['1', 'first'], ['2', "multi\nline"], ['3', null]])
        ->and($tables)->toBe(['keep']);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
