<?php

declare(strict_types=1);

namespace Restlytics\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Restlytics\Laravel\Support\Sql;

final class SqlNormalizeTest extends TestCase
{
    public function test_strips_numeric_literals(): void
    {
        $this->assertSame(
            'select * from users where id = ?',
            Sql::normalize('SELECT * FROM users WHERE id = 1'),
        );
    }

    public function test_strips_string_literals(): void
    {
        $this->assertSame(
            'select * from users where email = ?',
            Sql::normalize("SELECT * FROM users WHERE email = 'alice@example.com'"),
        );
    }

    public function test_two_different_literals_produce_the_same_template(): void
    {
        // The whole point: id=1 and id=2 must group together (N+1 fingerprint).
        $a = Sql::normalize('SELECT * FROM users WHERE id = 1');
        $b = Sql::normalize('SELECT * FROM users WHERE id = 2');
        $this->assertSame($a, $b);
    }

    public function test_collapses_in_lists_to_single_placeholder(): void
    {
        $this->assertSame(
            'select * from users where id in (?)',
            Sql::normalize('SELECT * FROM users WHERE id IN (1, 2, 3, 4, 5)'),
        );

        // Varying length lists must collapse to the SAME template.
        $short = Sql::normalize('SELECT * FROM users WHERE id IN (1, 2)');
        $long = Sql::normalize('SELECT * FROM users WHERE id IN (1, 2, 3, 4)');
        $this->assertSame($short, $long);
    }

    public function test_collapses_existing_placeholders_and_in_lists(): void
    {
        $this->assertSame(
            'select * from t where id in (?)',
            Sql::normalize('SELECT * FROM t WHERE id IN (?, ?, ?)'),
        );
    }

    public function test_squashes_whitespace_and_newlines(): void
    {
        $this->assertSame(
            'select id from users where active = ?',
            Sql::normalize("SELECT   id\n  FROM users\n\tWHERE active   =   1"),
        );
    }

    public function test_collapses_values_tuples(): void
    {
        $this->assertSame(
            'insert into t (a, b) values (?)',
            Sql::normalize('INSERT INTO t (a, b) VALUES (1, 2), (3, 4), (5, 6)'),
        );
    }

    public function test_handles_named_and_positional_bindings(): void
    {
        $this->assertSame(
            'select * from users where id = ? and name = ?',
            Sql::normalize('SELECT * FROM users WHERE id = :id AND name = $1'),
        );
    }

    public function test_does_not_mangle_identifiers_with_trailing_digits(): void
    {
        // column2 must stay column2 (it's an identifier, not a literal).
        $out = Sql::normalize('SELECT column2 FROM table1 WHERE column2 = 5');
        $this->assertStringContainsString('column2', $out);
        $this->assertStringContainsString('= ?', $out);
    }

    public function test_strips_decimal_and_hex_literals(): void
    {
        $this->assertSame(
            'select * from t where price > ? and flag = ?',
            Sql::normalize('SELECT * FROM t WHERE price > 19.99 AND flag = 0xFF'),
        );
    }
}
