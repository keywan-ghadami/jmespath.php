<?php
namespace JmesPath\Tests;

use JmesPath\Lexer;
use JmesPath\SyntaxErrorException;
use PHPUnit\Framework\TestCase;

/**
 * @covers JmesPath\Lexer
 */
class LexerTest extends TestCase
{
    public function inputProvider()
    {
        return [
            ['0', 'number'],
            ['1', 'number'],
            ['2', 'number'],
            ['3', 'number'],
            ['4', 'number'],
            ['5', 'number'],
            ['6', 'number'],
            ['7', 'number'],
            ['8', 'number'],
            ['9', 'number'],
            ['-1', 'number'],
            ['-1.5', 'number'],
            ['109.5', 'number'],
            ['.', 'dot'],
            ['{', 'lbrace'],
            ['}', 'rbrace'],
            ['[', 'lbracket'],
            [']', 'rbracket'],
            [':', 'colon'],
            [',', 'comma'],
            ['||', 'or'],
            ['*', 'star'],
            ['foo', 'identifier'],
            ['"foo"', 'quoted_identifier'],
            ['`true`', 'literal'],
            ['`false`', 'literal'],
            ['`null`', 'literal'],
            ['`"true"`', 'literal']
        ];
    }

    /**
     * @dataProvider inputProvider
     */
    public function testTokenizesInput($input, $type)
    {
        $l = new Lexer();
        $tokens = $l->tokenize($input);
        $this->assertEquals($tokens[0]['type'], $type);
    }

    public function testTokenizesJsonLiterals()
    {
        $l = new Lexer();
        $tokens = $l->tokenize('`null`, `false`, `true`, `"abc"`, `"ab\\"c"`,'
            . '`0`, `0.45`, `-0.5`');
        $this->assertNull($tokens[0]['value']);
        $this->assertFalse($tokens[2]['value']);
        $this->assertTrue($tokens[4]['value']);
        $this->assertEquals('abc', $tokens[6]['value']);
        $this->assertEquals('ab"c', $tokens[8]['value']);
        $this->assertSame(0, $tokens[10]['value']);
        $this->assertSame(0.45, $tokens[12]['value']);
        $this->assertSame(-0.5, $tokens[14]['value']);
    }

    public function testTokenizesJsonNumbers()
    {
        $l = new Lexer();
        $tokens = $l->tokenize('`10`, `1.2`, `-10.20e-10`, `1.2E+2`');
        $this->assertEquals(10, $tokens[0]['value']);
        $this->assertEquals(1.2, $tokens[2]['value']);
        $this->assertEquals(-1.02E-9, $tokens[4]['value']);
        $this->assertEquals(120, $tokens[6]['value']);
    }

    public function testCanWorkWithElidedJsonLiterals()
    {
        $l = new Lexer();
        $tokens = $l->tokenize('`foo`');
        $this->assertEquals('foo', $tokens[0]['value']);
        $this->assertEquals('literal', $tokens[0]['type']);
    }

    public function inputProviderForArithmetic()
    {
        return array(
            array('3 + 5', 1, 'arithmetic_plus_or_minus'),
            array('{ total: invoices[0] + 5 }', 7, 'arithmetic_plus_or_minus'),
            array('{ total: invoice.amount + invoice.tax }', 6, 'arithmetic_plus_or_minus'),
            array('{ "foo+test@example.com": 10 }', 1, 'quoted_identifier'),

            array('3 - 5', 1, 'arithmetic_plus_or_minus'),
            array('{ total: -3 + invoice.amout - invoice.tax }', 3, 'number'),
            array('{ total: -3 + invoice.amout - invoice.tax }', 8, 'arithmetic_plus_or_minus'),
            array('{ total: invoice.amount - invoice.tax }', 6, 'arithmetic_plus_or_minus'),
            array('{ total: invoices[-1] - 3}', 5, 'number'),
            array('{ total: invoices[-1] - 3}', 7, 'arithmetic_plus_or_minus'),

            array('3 * 5', 1, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: invoice.amount * invoice.tax }', 6, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: invoice.*.price[1] * invoice.tax }', 5, 'star'),
            array('{ total: invoice.*.price[1] * invoice.tax }', 11, 'arithmetic_multiply_or_divide_or_mod'),
            array('[[*], *]', 2, 'star'),
            array('[[*], *]', 5, 'star'),
            array('foo.*', 2, 'star'),
            array('`22` * 3', 1, 'arithmetic_multiply_or_divide_or_mod'),

            array('3 / 5', 1, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: invoice.amount / invoice.tax }', 6, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: invoices[3] / invoice.tax }', 7, 'arithmetic_multiply_or_divide_or_mod'),

            array('3 % 5', 1, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: invoice.amount % invoice.tax }', 6, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: invoices[2] % invoice.tax }', 7, 'arithmetic_multiply_or_divide_or_mod'),
            array('{ total: $.selected }', 3, 'root'),
        );
    }

    /**
     * @dataProvider inputProviderForArithmetic
     */
    public function testArithmetic($input, $index, $type)
    {
        $l = new Lexer();
        $tokens = $l->tokenize($input);
        $this->assertEquals($tokens[$index]['type'], $type);
    }
}
