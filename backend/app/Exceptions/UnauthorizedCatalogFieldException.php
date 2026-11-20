<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'un commerçant tente de soumettre un champ produit
 * qui n'a pas été autorisé (coché) par l'Admin pour son Type de Commerce.
 * Traduite en rejet HTTP 403 Forbidden par l'API.
 */
class UnauthorizedCatalogFieldException extends RuntimeException
{
    /**
     * @param  list<string>  $forbiddenFields
     */
    public function __construct(
        public readonly array $forbiddenFields,
        string $message = '',
    ) {
        $list = implode(', ', $forbiddenFields);
        $resolvedMessage = $message !== ''
            ? $message
            : "Accès refusé : le(s) champ(s) [{$list}] ne sont pas autorisés pour votre type de commerce.";

        parent::__construct($resolvedMessage, 403);
    }
}
