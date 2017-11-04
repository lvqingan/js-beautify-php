<?php
/*
 * This file is part of the FineUI package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class JSBeautify
{
    /**
     * 参数
     * indent_size: 缩进位数
     * indent_char: 缩进字符
     * indent_level: 起始缩进
     * preserve_newlines: 是否保留换行
     * @var array
     */
    private $options;

    /**
     * 是否添加<script>标签
     * @var bool
     */
    private $addScriptTags;

    /**
     * 缩进符
     * @var string
     */
    private $indentString;

    /**
     * 起始缩进
     * @var string
     */
    private $indentLevel;

    private $doBlockJustClosed;
    private $output = '';
    private $input;
    private $modes = [];
    private $currentMode;
    private $ifLineFlag;

    private $lastWord;
    private $varLine;
    private $varLineTainted;
    private $inCase;

    /**
     * 空白字符
     * @var string
     */
    private $whitespace = "\n\r\t";

    /**
     * 字符（字母数字下划线）
     * @var string
     */
    private $wordchar = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /**
     * 数字
     * @var string
     */
    private $digits = '0123456789';

    private $parserPos;
    private $lastType;
    private $lastText;
    private $tokenText;
    private $tokenType;

    /**
     * 运算符
     * @var array
     */
    private $punct = [];

    /**
     * 另一一行的关键词
     * @var array
     */
    private $lineStarters = [];
    private $prefix;

    public function __construct($sourceText, $options = [])
    {
        $this->options = [
            'indent_size'       => $options['indent_size'] ?? 4,
            'indent_char'       => $options['indent_char'] ?? ' ',
            'indent_level'      => $options['indent_level'] ?? 0,
            'preserve_newlines' => $options['preserve_newlines'] ?? true,
        ];

        $this->indentString = str_repeat($this->options['indent_char'], $this->options['indent_size']);
        $this->indentLevel = $this->options['indent_level'];

        $this->input = str_replace('</script>', '', str_replace('<script type="text/javascript">', '', $sourceText));

        // 源代码包含了<script>标签
        if (mb_strlen($this->input) != mb_strlen($sourceText)) {
            $this->output .= '<script type="text/javascript">';
            $this->addScriptTags = true;
        }

        $this->lastWord = '';
        $this->lastType = 'TK_START_EXPR';
        $this->lastText = '';

        $this->doBlockJustClosed = false;
        $this->varLine = false;
        $this->varLineTainted = false;

        $this->punct = explode(' ', '+ - * / % & ++ -- = += -= *= /= %= == === != !== > < >= <= >> << >>> >>>= >>= <<= && &= | || ! !! , : ? ^ ^= |= ::');

        $this->lineStarters = explode(',', 'continue,try,throw,return,var,if,switch,case,default,for,while,break,function');

        $this->currentMode = 'BLOCK';
        $this->modes[] = $this->currentMode;

        $this->parserPos = 0;
        $this->inCase = false;

        while (true) {
            $t = $this->getNextToken($this->parserPos);
            $this->tokenText = $t[0];
            $this->tokenType = $t[1];

            if ($this->tokenType == 'TK_EOF') {
                break;
            }

            switch ($this->tokenType) {
                case 'TK_START_EXPR':
                    $this->varLine = false;
                    $this->setMode('EXPRESSION');
                    if ($this->lastText == ';' || $this->lastType == 'TK_START_BLOCK') {
                        $this->printNewLine(null);
                    } else if ($this->lastType == 'TK_END_EXPR' || $this->lastType == 'TK_START_EXPR') {

                    } else if ($this->lastType != 'TK_WORD' && $this->lastType != 'TK_OPERATOR') {
                        $this->printSpace();
                    } else if (in_array($this->lastWord, $this->lineStarters)) {
                        $this->printSpace();
                    }

                    $this->printToken();
                    break;
                case 'TK_END_EXPR':
                    $this->printToken();
                    $this->restoreMode();
                    break;
                case 'TK_START_BLOCK':
                    if ($this->lastWord == 'do') {
                        $this->setMode('DO_BLOCK');
                    } else {
                        $this->setMode('BLOCK');
                    }
                    if ($this->lastType != 'TK_OPERATOR' && $this->lastType != 'TK_START_EXPR') {
                        if ($this->lastType == 'TK_START_BLOCK') {
                            $this->printNewLine(null);
                        } else {
                            $this->printSpace();
                        }
                    }
                    $this->printToken();
                    $this->indent();
                    break;
                case 'TK_END_BLOCK':
                    if ($this->lastType == 'TK_START_BLOCK') {
                        $this->trimOutput();
                        $this->unindent();
                    } else {
                        $this->unindent();
                        $this->printNewLine(null);
                    }
                    $this->printToken();
                    $this->restoreMode();
                    break;
                case 'TK_WORD':
                    if ($this->doBlockJustClosed) {
                        $this->printSpace();
                        $this->printToken();
                        $this->printSpace();
                        $this->doBlockJustClosed = false;
                        break;
                    }

                    if ($this->tokenText == 'case' || $this->tokenText == 'default') {
                        if ($this->lastText == ':') {
                            $this->removeIndent();
                        } else {
                            $this->unindent();
                            $this->printNewLine(null);
                            $this->indent();
                        }
                        $this->printToken();
                        $this->inCase = true;
                        break;
                    }

                    $this->prefix = 'NONE';

                    if ($this->lastType == 'TK_END_BLOCK') {
                        if (! in_array(strtolower($this->tokenText), ['else', 'catch', 'finally'])) {
                            $this->prefix = 'NEWLINE';
                        } else {
                            $this->prefix = 'SPACE';
                            $this->printSpace();
                        }
                    } else if ($this->lastType == 'TK_SEMICOLON' && ($this->currentMode == 'BLOCK' || $this->currentMode == 'DO_BLOCK')) {
                        $this->prefix = 'NEWLINE';
                    } else if ($this->lastType == 'TK_SEMICOLON' && $this->currentMode == 'EXPRESSION') {
                        $this->prefix = 'SPACE';
                    } else if ($this->lastType == 'TK_STRING') {
                        $this->prefix = 'NEWLINE';
                    } else if ($this->lastType == 'TK_WORD') {
                        $this->prefix = 'SPACE';
                    } else if ($this->lastType == 'TK_START_BLOCK') {
                        $this->prefix = 'NEWLINE';
                    } else if ($this->lastType == 'TK_END_EXPR') {
                        $this->printSpace();
                        $this->prefix = 'NEWLINE';
                    }

                    if ($this->lastType != 'TK_END_BLOCK' && in_array(strtolower($this->tokenText), ['else', 'catch', 'finally'])) {
                       $this->printNewLine(null);
                    } else if (in_array($this->tokenText, $this->lineStarters) || $this->prefix == 'NEWLINE') {
                        if ($this->lastText == 'else') {
                            $this->printSpace();
                        } else if (($this->lastType == 'TK_START_EXPR' || $this->lastText == '='  || $this->lastText == ',') && $this->tokenText == 'function') {

                        } else if ($this->lastType == 'TK_WORD' && ($this->lastText == 'return' || $this->lastText == 'throw')) {
                            $this->printSpace();
                        } else if ($this->lastType != 'TK_END_EXPR') {
                            if (($this->lastType != 'TK_START_EXPR' || $this->tokenText != 'var') && $this->lastText != ':') {
                               if ($this->tokenText == 'if' && $this->lastType == 'TK_WORD' && $this->lastWord == 'else') {
                                   $this->printSpace();
                               } else {
                                   $this->printNewLine(null);
                               }
                            }
                        } else {
                            if (in_array($this->tokenText, $this->lineStarters) && $this->lastText != ')') {
                                $this->printNewLine(null);
                            }
                        }
                    } else if ($this->prefix == 'SPACE') {
                        $this->printSpace();
                    }

                    $this->printToken();
                    $this->lastWord = $this->tokenText;

                    if ($this->tokenText == 'var') {
                        $this->varLine = true;
                        $this->varLineTainted = false;
                    }

                    if ($this->tokenText == 'if' || $this->tokenText == 'else') {
                        $this->ifLineFlag = true;
                    }
                    break;
                case 'TK_SEMICOLON':
                    $this->printToken();
                    $this->varLine = false;
                    break;
                case 'TK_STRING':
                    if ($this->lastType == 'TK_START_BLOCK' || $this->lastType == 'TK_END_BLOCK' || $this->lastType == 'TK_SEMICOLON') {
                        $this->printNewLine(null);
                    } else if ($this->lastType == 'TK_WORD') {
                        $this->printSpace();
                    }

                    $this->printToken();
                    break;
                case 'TK_OPERATOR':
                    $startDelim = true;
                    $endDelim = true;

                    if ($this->varLine && $this->tokenText != ',') {
                        $this->varLineTainted = true;
                        if ($this->tokenText == ':') {
                            $this->varLine = false;
                        }
                    }
                    if ($this->varLine && $this->tokenText == ',' && $this->currentMode == 'EXPRESSION') {
                        $this->varLineTainted = false;
                    }

                    if ($this->tokenText == ':' && $this->inCase) {
                        $this->printToken();
                        $this->printNewLine(null);
                        $this->inCase = false;
                        break;
                    }

                    if ($this->tokenText == '::') {
                        $this->printToken();
                        break;
                    }

                    if ($this->tokenText == ',') {
                        if ($this->varLine) {
                            if ($this->varLineTainted) {
                                $this->printToken();
                                $this->printNewLine(null);
                                $this->varLineTainted = false;
                            } else {
                                $this->printToken();
                                $this->printSpace();
                            }
                        } else if ($this->lastType == 'TK_END_BLOCK') {
                            $this->printToken();
                            $this->printNewLine(null);
                        } else {
                            if ($this->currentMode == 'BLOCK') {
                                $this->printToken();
                                $this->printNewLine(null);
                            } else {
                                $this->printToken();
                                $this->printSpace();
                            }
                        }
                        break;
                    } else if ($this->tokenText == '--' || $this->tokenText == '++') {
                        if ($this->lastText == ';') {
                            if ($this->currentMode == 'BLOCK') {
                                $this->printNewLine(null);
                                $startDelim = true;
                                $endDelim = false;
                            } else {
                                $startDelim = true;
                                $endDelim = false;
                            }
                        } else {
                            if ($this->lastText == '{') {
                                $this->printNewLine(null);
                            }
                            $startDelim = false;
                            $endDelim = false;
                        }
                    } else if (in_array($this->tokenText, ['!', '+', '-']) && in_array($this->lastText, ['return', 'case'])) {
                        $startDelim = true;
                        $endDelim = false;
                    } else if (in_array($this->tokenText, ['!', '+', '-']) && $this->lastType == 'TK_START_EXPR') {
                        $startDelim = false;
                        $endDelim = false;
                    } else if ($this->lastType == 'TK_OPERATOR') {
                        $startDelim = false;
                        $endDelim = false;
                    } else if ($this->lastType == 'TK_END_EXPR') {
                        $startDelim = true;
                        $endDelim = true;
                    } else if ($this->tokenText == '.') {
                        $startDelim = false;
                        $endDelim = false;
                    } else if ($this->tokenText == ':') {
                        if ($this->isTernaryOp()) {
                            $startDelim = true;
                        } else {
                            $startDelim = false;
                        }
                    }
                    if ($startDelim) {
                        $this->printSpace();
                    }
                    $this->printToken();

                    if ($endDelim) {
                        $this->printSpace();
                    }
                    break;
                case 'TK_BLOCK_COMMENT':
                    $this->printNewLine(null);
                    $this->printToken();
                    $this->printNewLine(null);
                    break;
                case 'TK_COMMENT':
                    $this->printSpace();
                    $this->printToken();
                    $this->printNewLine(null);
                    break;
                case 'TK_UNKNOWN':
                    $this->printToken();
                    break;
            }
            $this->lastType = $this->tokenType;
            $this->lastText = $this->tokenText;
        }
    }

    private function getNextToken(&$parserPos)
    {
        $newLines = 0;

        if ($parserPos >= mb_strlen($this->input)) {
            return ['', 'TK_EOF'];
        }

        $c = $this->getInputChar($parserPos);
        $parserPos++;

        while (mb_strpos($this->whitespace, $c) !== false) {
            if ($parserPos >= mb_strlen($this->input)) {
                return ['', 'TK_EOF'];
            }

            if ($c == "\n") {
                $newLines++;
            }

            $c = $this->getInputChar($parserPos);
            $parserPos++;
        }

        $wantNewLine = false;

        if ($this->options['preserve_newlines']) {
            if ($newLines > 1) {
                for ($i = 0; $i < 2; $i++) {
                    $this->printNewLine($i == 0);
                }
            }
            $wantNewLine = ($newLines == 1);
        }

        if (mb_strpos($this->wordchar, $c) !== false) {
            if ($parserPos < mb_strlen($this->input)) {
                while (mb_strpos($this->wordchar, $this->getInputChar($parserPos)) !== false) {
                    $c .= $this->getInputChar($parserPos);
                    $parserPos++;
                    if ($parserPos == mb_strlen($this->input)) {
                        break;
                    }
                }
            }

            if ($parserPos != mb_strlen($this->input) && preg_match('/^[0-9]+[Ee]$/', $c) && ($this->getInputChar($parserPos) == '-' || $this->getInputChar($parserPos) == '+')) {
                $sign = $this->getInputChar($parserPos);
                $parserPos++;

                $t = $this->getNextToken($parserPos);
                $c .= $sign . $t[0];

                return [$c, 'TK_WORD'];
            }

            if ($c == 'in') {
                return [$c, 'TK_OPERATOR'];
            }

            if ($wantNewLine && $this->lastType != 'TK_OPERATOR' && ! $this->ifLineFlag) {
                $this->printNewLine(null);
            }

            return [$c, 'TK_WORD'];
        }

        if ($c == '(' || $c == '[') {
            return [$c, 'TK_START_EXPR'];
        }

        if ($c == ')' || $c == ']') {
            return [$c, 'TK_END_EXPR'];
        }

        if ($c == '{') {
            return [$c, 'TK_START_BLOCK'];
        }

        if ($c == '}') {
            return [$c, 'TK_END_BLOCK'];
        }

        if ($c == ';') {
            return [$c, 'TK_SEMICOLON'];
        }

        if ($c == '/') {
            $comment = '';
            if ($this->getInputChar($parserPos) == '*') {
                $parserPos++;
                if ($parserPos < mb_strlen($this->input)) {
                    while (! ($this->getInputChar($parserPos) == '*' && $this->getInputChar($parserPos + 1) > "\0" && $this->getInputChar($parserPos + 1) == '/' && $parserPos < mb_strlen($this->input))) {
                        $comment .= $this->getInputChar($parserPos);
                        $parserPos++;
                        if ($parserPos >= mb_strlen($this->input)) {
                            break;
                        }
                    }
                }

                $parserPos += 2;

                return ['/*' . $comment . '*/', 'TK_BLOCK_COMMENT'];
            }

            if ($this->getInputChar($parserPos) == '/') {
                $comment = $c;

                while ($this->getInputChar($parserPos) != "\x0d" && $this->getInputChar($parserPos) != "\x0a") {
                    $comment .= $this->getInputChar($parserPos);
                    $parserPos++;

                    if ($parserPos >= mb_strlen($this->input)) {
                        break;
                    }
                }
                $parserPos++;
                if ($wantNewLine) {
                    $this->printNewLine(null);
                }

                return [$comment, 'TK_COMMENT'];
            }
        }

        if ($c == "'" || $c == '"' || ($c == '/' && (($this->lastType == 'TK_WORD' && $this->lastText == 'return') || in_array($this->lastType, ['TK_START_EXPR', 'TK_START_BLOCK', 'TK_END_BLOCK', 'TK_OPERATOR', 'TK_EOF', 'TK_SEMICOLON'])))) {
            $sep = $c;
            $esc = false;
            $resultingString = $c;

            if ($parserPos < mb_strlen($this->input)) {
                if ($sep == '/') {
                    $inCharClass = false;
                    while ($esc || $inCharClass || $this->getInputChar($parserPos) != $sep) {
                        $resultingString .= $this->getInputChar($parserPos);

                        if (! $esc) {
                            $esc = $this->getInputChar($parserPos) == '\\';
                            if ($this->getInputChar($parserPos) == '[') {
                                $inCharClass = true;
                            } else if ($this->getInputChar($parserPos) == ']') {
                                $inCharClass = false;
                            }
                        } else {
                            $esc = false;
                        }

                        $parserPos++;

                        if ($parserPos >= mb_strlen($this->input)) {
                            return [$resultingString, 'TK_STRING'];
                        }
                    }
                } else {
                    while ($esc || $this->getInputChar($parserPos) != $sep) {
                        $resultingString .= $this->getInputChar($parserPos);

                        if (! $esc) {
                            $esc = $this->getInputChar($parserPos) == '\\';
                        } else {
                            $esc = false;
                        }

                        $parserPos++;
                        if ($parserPos >= mb_strlen($this->input)) {
                            return [$resultingString, 'TK_STRING'];
                        }
                    }
                }
            }

            $parserPos += 1;

            $resultingString .= $sep;

            if ($sep == '/') {
                while ($parserPos < mb_strlen($this->input) && mb_strpos($this->wordchar, $this->getInputChar($parserPos)) !== false) {
                    $resultingString .= $this->getInputChar($parserPos);
                    $parserPos += 1;
                }
            }

            return [$resultingString, 'TK_STRING'];
        }

        if ($c == '#') {
            $sharp = '#';

            if ($parserPos < mb_strlen($this->input) && mb_strpos($this->digits, $this->getInputChar($parserPos)) !== false) {
                do {
                    $c = $this->getInputChar($parserPos);
                    $sharp .= $c;
                    $parserPos += 1;
                } while ($parserPos < mb_strlen($this->input) && $c != '#' && $c != '=');

                if ($c == '#') {
                    return [$sharp, 'TK_WORD'];
                } else {
                    return [$sharp, 'TK_OPERATOR'];
                }
            }
        }

        if ($c == '<' && mb_substr($this->input, $parserPos - 1, 3) == '<!--') {
            $parserPos += 3;

            return ['<!--', 'TK_COMMENT'];
        }

        if ($c == '-' && mb_substr($this->input, $parserPos - 1, 2) == '-->') {
            $parserPos += 2;
            if ($wantNewLine) {
                $this->printNewLine(null);
            }

            return ['-->', 'TK_COMMENT'];
        }

        if (in_array($c, $this->punct)) {
            while ($parserPos < mb_strlen($this->input) && in_array($c . $this->getInputChar($parserPos), $this->punct)) {
                $c .= $this->getInputChar($parserPos);
                $parserPos += 1;
                if ($parserPos >= mb_strlen($this->input)) {
                    break;
                }
            }

            return [$c, 'TK_OPERATOR'];
        }

        return [$c, 'TK_UNKNOWN'];
    }

    public function getResult()
    {
        if ($this->addScriptTags) {
            $this->output .= '</script>';
        }

        return $this->output;
    }

    private function trimOutput()
    {
        while (mb_strlen($this->output) > 0 && ($this->getOutputChar(mb_strlen($this->output) - 1) == ' ' || $this->getOutputChar(mb_strlen($this->output) - 1) == $this->indentString)) {
            $this->output = mb_substr_replace($this->output, '', mb_strlen($this->output) - 1, 1);
        }
    }

    private function getOutputChar($index)
    {
        return mb_substr($this->output, $index, 1);
    }

    private function getInputChar($index)
    {
        return mb_substr($this->input, $index, 1);
    }

    private function printNewLine($ignoreRepeated = null)
    {
        $this->ifLineFlag = false;
        $this->trimOutput();

        if (mb_strlen($this->output) == 0) {
            return;
        }

        if ($this->getOutputChar(mb_strlen($this->output) - 1) != "\n" || ! $ignoreRepeated) {
            $this->output .= PHP_EOL;
        }

        for ($i = 0; $i < $this->indentLevel; $i++) {
            $this->output .= $this->indentString;
        }
    }

    private function printSpace()
    {
        $lastOutput = ' ';
        if (mb_strlen($this->output) > 0) {
            $lastOutput = $this->getOutputChar(mb_strlen($this->output) - 1);
        }

        if ($lastOutput != ' ' && $lastOutput != "\n" && $lastOutput != $this->indentString) {
            $this->output .= ' ';
        }
    }

    private function printToken()
    {
        $this->output .= $this->tokenText;
    }

    private function indent()
    {
        $this->indentLevel++;
    }

    private function unindent()
    {
        if ($this->indentLevel > 0) {
            $this->indentLevel--;
        }
    }

    private function removeIndent()
    {
        if (mb_strlen($this->output) > 0 && $this->getOutputChar(mb_strlen($this->output) - 1) == $this->indentString) {
            $this->output = mb_substr_replace($this->output, '', mb_strlen($this->output) - 1, 1);
        }
    }

    private function setMode($mode)
    {
        $this->modes[] = $this->currentMode;
        $this->currentMode = $mode;
    }

    private function restoreMode()
    {
        $this->doBlockJustClosed = ($this->currentMode == 'DO_BLOCK');
        $this->currentMode = array_pop($this->modes);
    }

    private function isTernaryOp()
    {
        $level = 0;
        $colonCount = 0;

        for ($i = mb_strlen($this->output) - 1; $i >= 0; $i--) {
            switch ($this->getOutputChar($i)) {
                case ':':
                    if ($level == 0) {
                        $colonCount++;
                    }
                    break;
                case '?':
                    if ($level == 0) {
                        if ($colonCount == 0) {
                            return true;
                        } else {
                            $colonCount--;
                        }
                    }
                    break;
                case '{':
                    if ($level == 0) {
                        return false;
                    }
                    $level--;
                    break;
                case '(':
                case '[':
                    $level--;
                    break;
                case ')':
                case ']':
                case '}':
                    $level++;
                    break;
            }
        }
        return false;
    }
}

if (function_exists('mb_substr_replace') === false)
{
    function mb_substr_replace($string, $replacement, $start, $length = null, $encoding = null)
    {
        if (extension_loaded('mbstring') === true)
        {
            $string_length = (is_null($encoding) === true) ? mb_strlen($string) : mb_strlen($string, $encoding);

            if ($start < 0)
            {
                $start = max(0, $string_length + $start);
            }

            else if ($start > $string_length)
            {
                $start = $string_length;
            }

            if ($length < 0)
            {
                $length = max(0, $string_length - $start + $length);
            }

            else if ((is_null($length) === true) || ($length > $string_length))
            {
                $length = $string_length;
            }

            if (($start + $length) > $string_length)
            {
                $length = $string_length - $start;
            }

            if (is_null($encoding) === true)
            {
                return mb_substr($string, 0, $start) . $replacement . mb_substr($string, $start + $length, $string_length - $start - $length);
            }

            return mb_substr($string, 0, $start, $encoding) . $replacement . mb_substr($string, $start + $length, $string_length - $start - $length, $encoding);
        }

        return (is_null($length) === true) ? substr_replace($string, $replacement, $start) : substr_replace($string, $replacement, $start, $length);
    }
}
