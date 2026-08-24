<?php

/**
 * Partial override of Filament's email multi-factor translations. Laravel merges
 * this over the package file with array_replace_recursive, so only the changed
 * keys belong here.
 *
 * EmailAuthentication::verifyCode() returns false for both a wrong code and one
 * past its expiry, so the message has to cover both cases.
 */
return [

    'login_form' => [

        'code' => [

            'messages' => [

                'invalid' => 'The code you entered is invalid or expired.',

            ],

        ],

    ],

];
