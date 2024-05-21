<?php

namespace FpDbTest;

require 'vendor/autoload.php';

use Exception;
use mysqli;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Utils\Error;


class Database implements DatabaseInterface
{
    private mysqli $mysqli;
    private const SKIP_VALUE = '__SKIP__';
    private const VALID_CONTEXTS = [
        'SELECT', 'FROM', 'WHERE', 'INSERT INTO', 'VALUES',
        'UPDATE', 'SET', 'JOIN', 'ON', 'ORDER BY',
        'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET', 'AND', 'OR'
    ];

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = [], bool $validateMysql = false): string
    {
        $result = '';
        $length = strlen($query);
        $argIndex = 0;
        $insideConditionalBlock = false;
        $conditionalBlockContent = '';
        $placeholderCount = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($query[$i] === '{') {
                if ($insideConditionalBlock) {
                    throw new Exception('Nested conditional blocks are not supported');
                }
                $insideConditionalBlock = true;
                continue;
            }

            if ($query[$i] === '}') {
                if (!$insideConditionalBlock) {
                    throw new Exception('Unexpected closing brace');
                }
                if (!$this->shouldSkip($conditionalBlockContent, $args, $argIndex)) {
                    $result .= $this->processPlaceholders($conditionalBlockContent, $args, $argIndex, $placeholderCount);
                }
                $insideConditionalBlock = false;
                $conditionalBlockContent = '';
                continue;
            }

            if ($insideConditionalBlock) {
                $conditionalBlockContent .= $query[$i];
            } else {
                if ($query[$i] === '?') {
                    $specifier = trim($query[$i + 1] ?? '');
                    switch ($specifier) {
                        case 'd':
                        case 'f':
                        case 'a':
                        case '#':
                            $this->validateContext($query, $i);
                            $result .= $this->processPlaceholder('?' . $specifier, $args[$argIndex++]);
                            $i++;
                            $placeholderCount++;
                            break;
                        default:
                            if ($specifier !== '') {
                                throw new Exception('Unsupported placeholder type: ?' . $specifier);
                            }
                            $this->validateContext($query, $i);
                            $result .= $this->processPlaceholder('?', $args[$argIndex++]);
                            $placeholderCount++;
                            break;
                    }
                } else {
                    $result .= $query[$i];
                }
            }
        }

        if ($insideConditionalBlock) {
            throw new Exception('Unclosed conditional block');
        }

        if ($placeholderCount !== count($args)) {
            $numSkipValues = count(array_filter($args, fn ($arg) => $arg === self::SKIP_VALUE));
            if ($placeholderCount !== count($args) - $numSkipValues) {
                throw new Exception('Wrong number of placeholders');
            }
        }

        if (!is_null($err = $this->validateByPhpMyAdmin($result))) {
            throw new Exception($err);
        }

        if ($validateMysql && !is_null($err = $this->validateByMySql($result))) {
            throw new Exception($err);
        }

        return $result;
    }

    private function shouldSkip(string $content, array $args, int $startIndex): bool
    {
        $length = strlen($content);
        $argIndex = $startIndex;

        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === '?') {
                $specifier = trim($content[$i + 1] ?? '');
                switch ($specifier) {
                    case 'd':
                    case 'f':
                    case 'a':
                    case '#':
                        $arg = $args[$argIndex++];
                        if ($arg === self::SKIP_VALUE) {
                            return true;
                        }
                        $i++;
                        break;
                    default:
                        $arg = $args[$argIndex++];
                        if ($arg === self::SKIP_VALUE) {
                            return true;
                        }
                        break;
                }
            }
        }

        return false;
    }

    private function processPlaceholders(string $content, array $args, int &$argIndex, int &$placeholderCount): string
    {
        $result = '';
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === '?') {
                $specifier = trim($content[$i + 1] ?? '');
                switch ($specifier) {
                    case 'd':
                    case 'f':
                    case 'a':
                    case '#':
                        $this->validateContext($content, $i);
                        $result .= $this->processPlaceholder('?' . $specifier, $args[$argIndex++]);
                        $i++;
                        $placeholderCount++;
                        break;
                    default:
                        $this->validateContext($content, $i);
                        $result .= $this->processPlaceholder('?', $args[$argIndex++]);
                        $placeholderCount++;
                        break;
                }
            } else {
                $result .= $content[$i];
            }
        }

        return $result;
    }

    private function processPlaceholder(string $placeholder, $arg)
    {
        switch ($placeholder) {
            case '?d':
                return $this->escapeInt($arg);
            case '?f':
                return $this->escapeFloat($arg);
            case '?a':
                return $this->escapeArray($arg);
            case '?#':
                return $this->escapeIdentifierOrArray($arg);
            case '?':
                return $this->escapeValue($arg);
            default:
                throw new Exception('Unsupported placeholder type: ' . $placeholder);
        }
    }

    private function escapeInt($value)
    {
        return is_null($value) ? 'NULL' : (int)$value;
    }

    private function escapeFloat($value)
    {
        return is_null($value) ? 'NULL' : (float)$value;
    }

    private function escapeArray($array)
    {
        if (!is_array($array)) {
            throw new Exception('Expected array for ?a placeholder');
        }
        $escapedValues = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $escapedValues[] = $this->escapeIdentifier($key) . ' = ' . $this->escapeValue($value);
            } else {
                $escapedValues[] = $this->escapeValue($value);
            }
        }
        return implode(', ', $escapedValues);
    }

    private function escapeIdentifierOrArray($arg)
    {
        if (is_array($arg)) {
            $escapedIdentifiers = array_map([$this, 'escapeIdentifier'], $arg);
            return implode(', ', $escapedIdentifiers);
        } else {
            return $this->escapeIdentifier($arg);
        }
    }

    private function escapeValue($value)
    {
        if (is_array($value)) {
            throw new Exception('Unexpected array value');
        }
        if (is_null($value)) {
            return 'NULL';
        } elseif (is_int($value) || is_float($value)) {
            return $value;
        } elseif (is_bool($value)) {
            return $value ? 1 : 0;
        } elseif (is_string($value)) {
            return '\'' . $this->mysqli->real_escape_string($value) . '\'';
        } else {
            throw new Exception('Unsupported value type');
        }
    }

    private function escapeIdentifier($identifier)
    {
        if (!is_string($identifier) || !preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new Exception('Invalid identifier: ' . $identifier);
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function validateContext(string $query, int $pos): void
    {
        $context = $this->getContext($query, $pos);
        if (!in_array($context, self::VALID_CONTEXTS, true)) {
            throw new Exception('Invalid placeholder context: ' . $context);
        }
    }

    private function getContext(string $query, int $pos): string
    {
        $keywords = implode('|', self::VALID_CONTEXTS);
        preg_match_all('/\b(' . $keywords . ')\b/i', substr($query, 0, $pos), $matches, PREG_OFFSET_CAPTURE);

        $context = '';
        $lastPos = -1;
        foreach ($matches[1] as $match) {
            if ($match[1] > $lastPos) {
                $context = $match[0];
                $lastPos = $match[1];
            }
        }

        return $context;
    }
    private function validateByMySql($query)
    {
        $err = null;
        $query = "EXPLAIN " .
            preg_replace(
                array(
                    "/#[^\n\r;]*([\n\r;]|$)/",
                    "/[Ss][Ee][Tt]\s+\@[A-Za-z0-9_]+\s*:?=\s*[^;]+(;|$)/",
                    "/;\s*;/",
                    "/;\s*$/",
                    "/;/"
                ),
                array("", "", ";", "", "; EXPLAIN "),
                $query
            );
        mysqli_report(MYSQLI_REPORT_OFF);

        foreach (explode(';', $query) as $q) {
            $result = @$this->mysqli->query($q);
            $err = !$result ? $this->mysqli->error : null;
            if (!is_object($result) && !$err) $err = "Unknown SQL error";
        }

        mysqli_report(MYSQLI_REPORT_ALL);

        return $err;
    }

    private function validateByPhpMyAdmin($query)
    {
        $lexer = new Lexer($query, false);
        $parser = new Parser($lexer->list);
        $errors = Error::get([$lexer, $parser]);
        if (count($errors) === 0) return null;

        $output = Error::format($errors);
        return implode(" ", $output);
    }
    public function skip()
    {
        return self::SKIP_VALUE;
    }
}
