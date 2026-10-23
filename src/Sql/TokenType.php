<?php

declare(strict_types=1);

namespace AmjadIqbal\SqlDashboard\Sql;

enum TokenType
{
    case Word;
    case QuotedIdentifier;
    case String;
    case Number;
    case Variable;
    case Placeholder;
    case Semicolon;
    case Punctuation;
    case Comment;
}
