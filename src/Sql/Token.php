<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $text,
        public int $offset,
    ) {}

    public function is(TokenType $type, ?string $word = null): bool
    {
        return $this->type === $type && ($word === null || strcasecmp($this->text, $word) === 0);
    }

    public function upper(): string
    {
        return strtoupper($this->text);
    }

    /** The name a Word or QuotedIdentifier stands for: quotes removed, doubled quote characters undone. */
    public function identifierName(): string
    {
        if ($this->type !== TokenType::QuotedIdentifier || strlen($this->text) < 2) {
            return $this->text;
        }
        $open = $this->text[0];
        $close = $open === '[' ? ']' : $open;
        $inner = substr($this->text, 1, -1);

        return $open === '[' ? $inner : str_replace($close.$close, $close, $inner);
    }

    /** The variable name of a {name} token. */
    public function variableName(): string
    {
        return substr($this->text, 1, -1);
    }
}
