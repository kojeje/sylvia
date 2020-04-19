<?php

namespace MailPoetVendor\Egulias\EmailValidator\Parser;

if (!defined('ABSPATH')) exit;


use MailPoetVendor\Egulias\EmailValidator\EmailLexer;
use MailPoetVendor\Egulias\EmailValidator\Exception\AtextAfterCFWS;
use MailPoetVendor\Egulias\EmailValidator\Exception\ConsecutiveDot;
use MailPoetVendor\Egulias\EmailValidator\Exception\CRLFAtTheEnd;
use MailPoetVendor\Egulias\EmailValidator\Exception\CRLFX2;
use MailPoetVendor\Egulias\EmailValidator\Exception\CRNoLF;
use MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingQPair;
use MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingATEXT;
use MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingCTEXT;
use MailPoetVendor\Egulias\EmailValidator\Exception\UnclosedComment;
use MailPoetVendor\Egulias\EmailValidator\Exception\UnclosedQuotedString;
use MailPoetVendor\Egulias\EmailValidator\Warning\CFWSNearAt;
use MailPoetVendor\Egulias\EmailValidator\Warning\CFWSWithFWS;
use MailPoetVendor\Egulias\EmailValidator\Warning\Comment;
use MailPoetVendor\Egulias\EmailValidator\Warning\QuotedPart;
use MailPoetVendor\Egulias\EmailValidator\Warning\QuotedString;
abstract class Parser
{
    /**
     * @var \Egulias\EmailValidator\Warning\Warning[]
     */
    protected $warnings = [];
    /**
     * @var EmailLexer
     */
    protected $lexer;
    /**
     * @var int
     */
    protected $openedParenthesis = 0;
    public function __construct(\MailPoetVendor\Egulias\EmailValidator\EmailLexer $lexer)
    {
        $this->lexer = $lexer;
    }
    /**
     * @return \Egulias\EmailValidator\Warning\Warning[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }
    /**
     * @param string $str
     */
    public abstract function parse($str);
    /** @return int */
    public function getOpenedParenthesis()
    {
        return $this->openedParenthesis;
    }
    /**
     * validateQuotedPair
     */
    protected function validateQuotedPair()
    {
        if (!($this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::INVALID || $this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::C_DEL)) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingQPair();
        }
        $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\QuotedPart::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\QuotedPart($this->lexer->getPrevious()['type'], $this->lexer->token['type']);
    }
    protected function parseComments()
    {
        $this->openedParenthesis = 1;
        $this->isUnclosedComment();
        $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\Comment::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\Comment();
        while (!$this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_CLOSEPARENTHESIS)) {
            if ($this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_OPENPARENTHESIS)) {
                $this->openedParenthesis++;
            }
            $this->warnEscaping();
            $this->lexer->moveNext();
        }
        $this->lexer->moveNext();
        if ($this->lexer->isNextTokenAny(array(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::GENERIC, \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_EMPTY))) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingATEXT();
        }
        if ($this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_AT)) {
            $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\CFWSNearAt::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\CFWSNearAt();
        }
    }
    /**
     * @return bool
     */
    protected function isUnclosedComment()
    {
        try {
            $this->lexer->find(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_CLOSEPARENTHESIS);
            return \true;
        } catch (\RuntimeException $e) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\UnclosedComment();
        }
    }
    protected function parseFWS()
    {
        $previous = $this->lexer->getPrevious();
        $this->checkCRLFInFWS();
        if ($this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_CR) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\CRNoLF();
        }
        if ($this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::GENERIC) && $previous['type'] !== \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_AT) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\AtextAfterCFWS();
        }
        if ($this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_LF || $this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::C_NUL) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingCTEXT();
        }
        if ($this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_AT) || $previous['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_AT) {
            $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\CFWSNearAt::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\CFWSNearAt();
        } else {
            $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\CFWSWithFWS::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\CFWSWithFWS();
        }
    }
    protected function checkConsecutiveDots()
    {
        if ($this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_DOT && $this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_DOT)) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\ConsecutiveDot();
        }
    }
    /**
     * @return bool
     */
    protected function isFWS()
    {
        if ($this->escaped()) {
            return \false;
        }
        if ($this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_SP || $this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_HTAB || $this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_CR || $this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_LF || $this->lexer->token['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::CRLF) {
            return \true;
        }
        return \false;
    }
    /**
     * @return bool
     */
    protected function escaped()
    {
        $previous = $this->lexer->getPrevious();
        if ($previous['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_BACKSLASH && $this->lexer->token['type'] !== \MailPoetVendor\Egulias\EmailValidator\EmailLexer::GENERIC) {
            return \true;
        }
        return \false;
    }
    /**
     * @return bool
     */
    protected function warnEscaping()
    {
        if ($this->lexer->token['type'] !== \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_BACKSLASH) {
            return \false;
        }
        if ($this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::GENERIC)) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingATEXT();
        }
        if (!$this->lexer->isNextTokenAny(array(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_SP, \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_HTAB, \MailPoetVendor\Egulias\EmailValidator\EmailLexer::C_DEL))) {
            return \false;
        }
        $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\QuotedPart::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\QuotedPart($this->lexer->getPrevious()['type'], $this->lexer->token['type']);
        return \true;
    }
    /**
     * @param bool $hasClosingQuote
     *
     * @return bool
     */
    protected function checkDQUOTE($hasClosingQuote)
    {
        if ($this->lexer->token['type'] !== \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_DQUOTE) {
            return $hasClosingQuote;
        }
        if ($hasClosingQuote) {
            return $hasClosingQuote;
        }
        $previous = $this->lexer->getPrevious();
        if ($this->lexer->isNextToken(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::GENERIC) && $previous['type'] === \MailPoetVendor\Egulias\EmailValidator\EmailLexer::GENERIC) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\ExpectingATEXT();
        }
        try {
            $this->lexer->find(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_DQUOTE);
            $hasClosingQuote = \true;
        } catch (\Exception $e) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\UnclosedQuotedString();
        }
        $this->warnings[\MailPoetVendor\Egulias\EmailValidator\Warning\QuotedString::CODE] = new \MailPoetVendor\Egulias\EmailValidator\Warning\QuotedString($previous['value'], $this->lexer->token['value']);
        return $hasClosingQuote;
    }
    protected function checkCRLFInFWS()
    {
        if ($this->lexer->token['type'] !== \MailPoetVendor\Egulias\EmailValidator\EmailLexer::CRLF) {
            return;
        }
        if (!$this->lexer->isNextTokenAny(array(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_SP, \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_HTAB))) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\CRLFX2();
        }
        if (!$this->lexer->isNextTokenAny(array(\MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_SP, \MailPoetVendor\Egulias\EmailValidator\EmailLexer::S_HTAB))) {
            throw new \MailPoetVendor\Egulias\EmailValidator\Exception\CRLFAtTheEnd();
        }
    }
}
