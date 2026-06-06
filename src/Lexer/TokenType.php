<?php declare(strict_types=1);
namespace Ols\PhpRuler\Lexer;

enum TokenType
{
    case T_INTEGER;
    case T_INTEGER_OVERFLOW;
    case T_FLOAT;
    case T_STRING;
    case T_BOOLEAN;
    case T_NULL;
    case T_PLUS;
    case T_MINUS;
    case T_MULTIPLY;
    case T_DIVIDE;
    case T_MODULO;
    case T_GTE;
    case T_LTE;
    case T_NOT_EQUAL;
    case T_GT;
    case T_LT;
    case T_EQUAL;
    case T_AND;
    case T_OR;
    case T_NOT;
    case T_IN;
    case T_LPAREN;
    case T_RPAREN;
    case T_LBRACKET;
    case T_RBRACKET;
    case T_COMMA;
    case T_COALESCE;
    case T_QUESTION;
    case T_COLON;
    case T_IDENTIFIER;
    case T_EOF;
}
